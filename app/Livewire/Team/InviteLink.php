<?php

namespace App\Livewire\Team;

use App\Models\Project;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Component;

class InviteLink extends Component
{
    use AuthorizesRequests;

    public string $email;

    public string $role = 'member';

    public string $emails = '';

    public ?string $project = null;

    public string $projectRole = 'viewer';

    public array $bulkResults = [];

    protected $rules = [
        'email' => 'required|email',
        'role' => 'required|string',
    ];

    public function mount()
    {
        $this->email = isDev() ? 'test3@example.com' : '';
    }

    public function viaEmail()
    {
        $this->generateInviteLink(sendEmail: true);
    }

    public function viaLink()
    {
        $this->generateInviteLink(sendEmail: false);
    }

    private function invitationUrl(string $routeName, array $parameters): string
    {
        $fqdn = instanceSettings()->fqdn;
        if (filled($fqdn)) {
            return rtrim($fqdn, '/').route($routeName, $parameters, false);
        }

        return route($routeName, $parameters);
    }

    private function generateInviteLink(bool $sendEmail = false)
    {
        try {
            $this->authorize('manageInvitations', currentTeam());
            $this->validate();

            $this->ensureCanInviteRole();
            $this->ensureProjectBelongsToTeam();

            $result = $this->inviteEmail($this->email, $sendEmail);
            $this->email = $result['email'];
            if ($result['status'] === 'member') {
                return handleError(livewire: $this, customErrorMessage: "$this->email is already a member of ".currentTeam()->name.'.');
            }
            if ($result['status'] === 'pending') {
                return handleError(livewire: $this, customErrorMessage: "Pending invitation already exists for $this->email.");
            }
            $this->dispatch('success', $sendEmail ? 'Invitation sent via email.' : 'Invitation link generated.');
            $this->dispatch('refreshInvitations');
        } catch (\Throwable $e) {
            $error_message = $e->getMessage();
            if ($e->getCode() === '23505') {
                $error_message = 'Invitation already sent.';
            }

            return handleError(error: $e, livewire: $this, customErrorMessage: $error_message);
        }
    }

    /**
     * Invites one address and reports what happened: "invited", "member" or "pending".
     *
     * @return array{email: string, status: string, link: ?string}
     */
    private function inviteEmail(string $email, bool $sendEmail): array
    {
        $email = strtolower(trim($email));

        $existingMember = currentTeam()->members()->where('email', $email)->first();
        // A member who never set a password is locked out, so they get a fresh login link instead of an error.
        if ($existingMember && $existingMember->force_password_reset !== true) {
            return ['email' => $email, 'status' => 'member', 'link' => null];
        }
        $invitation = TeamInvitation::ownedByCurrentTeam()->whereEmail($email)->first();
        if (! is_null($invitation)) {
            if ($invitation->isValid()) {
                return ['email' => $email, 'status' => 'pending', 'link' => $invitation->link];
            }
            $invitation->delete();
        }

        // Look up the user after the expired invitation is removed, because that can delete an unused account.
        $uuid = new_public_id(32);
        $link = $this->invitationUrl('team.invitation.show', ['uuid' => $uuid]);
        $user = User::whereEmail($email)->first();

        // A user who never set a password cannot log in, so they get a magic link with a new temporary password.
        if (is_null($user) || $user->isUnusedInvitedAccount() || $existingMember) {
            $password = Str::password();
            if (is_null($user)) {
                $user = User::create([
                    'name' => str($email)->before('@'),
                    'email' => $email,
                    'password' => Hash::make($password),
                    'force_password_reset' => true,
                ]);
            } else {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
            $token = Crypt::encryptString("{$user->email}@@@{$uuid}@@@{$password}");
            $link = $this->invitationUrl('auth.link', ['token' => $token]);
        }

        $invitation = TeamInvitation::firstOrCreate([
            'team_id' => currentTeam()->id,
            'uuid' => $uuid,
            'email' => $email,
            'role' => $this->role,
            'project_id' => $this->projectId(),
            'project_role' => $this->projectId() ? $this->projectRole : null,
            'link' => $link,
            'via' => $sendEmail ? 'email' : 'link',
        ]);
        auditLog('ui.team_invitation.created', [
            'team_id' => currentTeam()->id,
            'invitation_uuid' => $invitation->uuid,
            'invitation_email' => $invitation->email,
            'role' => $invitation->role,
            'via' => $invitation->via,
        ]);
        if ($sendEmail) {
            $mail = new MailMessage;
            $mail->view('emails.invitation-link', [
                'team' => currentTeam()->name,
                'invitation_link' => $link,
            ]);
            $mail->subject('You have been invited to '.currentTeam()->name.' on '.config('app.name').'.');
            send_user_an_email($mail, $email);
        }

        return ['email' => $email, 'status' => 'invited', 'link' => $link];
    }

    /**
     * Invites every address of the list with the same role (and optional project) and lists the outcome per address.
     */
    public function bulkInvite(bool $sendEmail = false)
    {
        try {
            $this->authorize('manageInvitations', currentTeam());
            $this->validate([
                'emails' => 'required|string|max:20000',
                'role' => 'required|string',
            ]);
            $this->ensureCanInviteRole();
            $this->ensureProjectBelongsToTeam();

            $addresses = collect(preg_split('/[\s,;]+/', $this->emails))
                ->map(fn ($email) => strtolower(trim($email)))
                ->filter()
                ->unique()
                ->values();
            if ($addresses->count() > 100) {
                throw new \Exception('Invite at most 100 addresses at once.');
            }

            $this->bulkResults = $addresses->map(function (string $email) use ($sendEmail) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return ['email' => $email, 'status' => 'invalid', 'link' => null];
                }
                try {
                    return $this->inviteEmail($email, $sendEmail);
                } catch (\Throwable $e) {
                    return ['email' => $email, 'status' => 'error', 'link' => null];
                }
            })->all();

            $invited = collect($this->bulkResults)->where('status', 'invited')->count();
            $this->dispatch('success', "$invited of {$addresses->count()} invitations created.");
            $this->dispatch('refreshInvitations');
        } catch (\Throwable $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    private function ensureCanInviteRole(): void
    {
        // Prevent privilege escalation: users cannot invite someone with higher privileges
        $userRole = auth()->user()->role();
        if (is_null($userRole) || ($userRole === 'member' && in_array($this->role, ['admin', 'owner']))) {
            throw new \Exception('Members cannot invite admins or owners.');
        }
        if ($userRole === 'admin' && $this->role === 'owner') {
            throw new \Exception('Admins cannot invite owners.');
        }
    }

    private function ensureProjectBelongsToTeam(): void
    {
        if (! in_array($this->projectRole, ['viewer', 'deployer', 'manager'], true)) {
            throw new \Exception('Unknown project access.');
        }
        if ($this->projectId() && ! Project::ownedByCurrentTeam()->whereKey($this->projectId())->exists()) {
            throw new \Exception('Project not found.');
        }
    }

    /**
     * Project access only applies to members: admins and owners see every project.
     */
    private function projectId(): ?int
    {
        return $this->role === 'member' && filled($this->project) ? (int) $this->project : null;
    }
}
