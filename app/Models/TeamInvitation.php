<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamInvitation extends Model
{
    protected $fillable = [
        'team_id',
        'uuid',
        'email',
        'role',
        'project_id',
        'project_role',
        'link',
        'via',
    ];

    protected $casts = [
        'link' => 'encrypted',
    ];

    /**
     * Set the email attribute to lowercase.
     */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower($value);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public static function ownedByCurrentTeam()
    {
        return TeamInvitation::whereTeamId(currentTeam()->id);
    }

    public function isValid(): bool
    {
        if (! $this->hasExpired()) {
            return true;
        } else {
            $this->delete();
            $user = User::whereEmail($this->email)->first();
            if (filled($user)) {
                $user->deleteIfNotVerifiedAndForcePasswordReset();
            }

            return false;
        }
    }

    public function hasExpired(): bool
    {
        return $this->created_at->diffInDays(now()) > config('constants.invitation.link.expiration_days');
    }

    /**
     * Adds the user to the team with the invited role, limited to the invited project when there is one.
     */
    public function attachUser(User $user): void
    {
        $user->teams()->attach($this->team_id, [
            'role' => $this->role,
            'restricted_to_projects' => $this->project_id !== null,
        ]);
        if ($this->project_id) {
            $user->projects()->syncWithoutDetaching([$this->project_id => ['role' => $this->project_role ?? 'viewer']]);
        }
    }
}
