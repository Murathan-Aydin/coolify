<?php

namespace App\Livewire\Team;

use App\Enums\AccessScope;
use App\Enums\Role;
use App\Livewire\Team\Member\Access;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Access\Grant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class InviteLink extends Component
{
    use AuthorizesRequests;

    public string $email;

    public string $role = 'member';

    /**
     * Addresses for the bulk invitation (fork), one per line or separated by commas.
     */
    public string $emails = '';

    /**
     * Outcome per address of the last bulk invitation (fork).
     *
     * @var list<array{email: string, status: string, link: ?string}>
     */
    public array $bulkResults = [];

    protected function rules(): array
    {
        return [
            'email' => 'required|email',
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    /**
     * Grants for an invited member, set by the access editor. Checked again on invite.
     *
     * @var list<array{scope: string, uuid: string|null, levels: list<string>}>
     */
    public array $grants = [];

    public function mount()
    {
        $this->email = isDev() ? 'test3@example.com' : '';
        $this->grants = Grant::listToArray(AccessResolver::defaultMemberGrants());
    }

    /**
     * @param  list<array{scope: string, uuid: string|null, levels: list<string>}>  $access
     */
    #[On('invitation-access-chosen')]
    public function setGrants(array $access): void
    {
        $this->grants = Grant::listToArray(Grant::listFrom($access));
    }

    public function viaEmail()
    {
        $this->generateInviteLink(sendEmail: true);
    }

    public function viaLink()
    {
        $this->generateInviteLink(sendEmail: false);
    }

    /**
     * Build the link from the instance URL, not from the request host, which a client can set.
     */
    private function invitationUrl(string $routeName, array $parameters): string
    {
        return rtrim(base_url(), '/').route($routeName, $parameters, false);
    }

    private function generateInviteLink(bool $sendEmail = false)
    {
        try {
            $this->authorize('manageInvitations', currentTeam());
            $this->validate();

            $this->ensureCanInviteRole();
            $access = $this->validatedAccess();

            $result = $this->inviteEmail($this->email, $sendEmail, $access);
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
     * Invites every address of the list with the same role and scopes, and lists the outcome per address (fork).
     */
    public function bulkInvite(bool $sendEmail = false)
    {
        try {
            $this->authorize('manageInvitations', currentTeam());
            $this->validate([
                'emails' => 'required|string|max:20000',
                'role' => ['required', Rule::enum(Role::class)],
            ]);
            $this->ensureCanInviteRole();
            $access = $this->validatedAccess();

            $addresses = collect(preg_split('/[\s,;]+/', $this->emails))
                ->map(fn ($email) => strtolower(trim($email)))
                ->filter()
                ->unique()
                ->values();
            if ($addresses->count() > 100) {
                throw new \Exception('Invite at most 100 addresses at once.');
            }

            $this->bulkResults = $addresses->map(function (string $email) use ($sendEmail, $access) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return ['email' => $email, 'status' => 'invalid', 'link' => null];
                }
                try {
                    return $this->inviteEmail($email, $sendEmail, $access);
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

    /**
     * Invites one address and reports what happened: "invited", "member" or "pending".
     *
     * @param  list<array{scope: string, uuid: string|null, levels: list<string>}>|null  $access
     * @return array{email: string, status: string, link: ?string}
     */
    private function inviteEmail(string $email, bool $sendEmail, ?array $access): array
    {
        $email = strtolower(trim($email));
        $teamId = currentTeam()->id;

        $existingMember = currentTeam()->members()->where('email', $email)->first();
        // A member who never set a password is locked out, so they get a fresh login link instead of an error (fork).
        $lockedOutMember = $existingMember && $this->isLockedOutMember($existingMember, $teamId);
        if ($existingMember && ! $lockedOutMember) {
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
        if (is_null($user) || $user->canReceiveInvitationLoginFrom($teamId) || $lockedOutMember) {
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
            'team_id' => $teamId,
            'uuid' => $uuid,
            'email' => $email,
            'role' => $this->role,
            'link' => $link,
            'via' => $sendEmail ? 'email' : 'link',
        ], ['access' => $access]);
        auditLog('ui.team_invitation.created', [
            'team_id' => $teamId,
            'invitation_uuid' => $invitation->uuid,
            'invitation_email' => $invitation->email,
            'role' => $invitation->role,
            'via' => $invitation->via,
            'access' => $invitation->access,
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
     * A member of this team who never logged in with a password of their own (fork). They may get a new magic
     * link only while no other team shares the account, because that link signs in as them everywhere.
     */
    private function isLockedOutMember(User $member, int $teamId): bool
    {
        if ($member->force_password_reset !== true || $member->hasVerifiedEmail()) {
            return false;
        }

        $sharesAnotherTeam = $member->teams()
            ->where('teams.id', '!=', $teamId)
            ->where(fn ($query) => $query->where('personal_team', false)
                ->orWhereHas('members', fn ($members) => $members->where('users.id', '!=', $member->id)))
            ->exists();
        if ($sharesAnotherTeam) {
            return false;
        }

        return TeamInvitation::whereEmail($member->email)
            ->where('team_id', '!=', $teamId)
            ->get()
            ->every(fn (TeamInvitation $invitation) => $invitation->hasExpired());
    }

    /**
     * Prevent privilege escalation: users cannot invite someone with higher privileges.
     */
    private function ensureCanInviteRole(): void
    {
        $userRole = Role::tryFrom((string) auth()->user()->role());
        if (is_null($userRole) || ($userRole === Role::MEMBER && Role::from($this->role)->gt(Role::MEMBER))) {
            throw new \Exception('Members cannot invite admins or owners.');
        }
        if ($userRole === Role::ADMIN && Role::from($this->role) === Role::OWNER) {
            throw new \Exception('Admins cannot invite owners.');
        }
    }

    /**
     * The checked grants for a member invitation; admins and owners get no grants (they have full access).
     *
     * @return list<array{scope: string, uuid: string|null, levels: list<string>}>|null
     */
    private function validatedAccess(): ?array
    {
        if ($this->role !== 'member') {
            return null;
        }

        $grants = Grant::listFrom($this->grants);
        $validTargets = Access::validTargets(currentTeam()->id);
        foreach ($grants as $grant) {
            $target = $grant->scope === AccessScope::Team ? 'team' : "{$grant->scope->value}:{$grant->uuid}";
            if ($grant->levels === [] || ! in_array($target, $validTargets, true)) {
                throw new \Exception('Choose valid member access.');
            }
        }

        return Grant::listToArray($grants);
    }
}
