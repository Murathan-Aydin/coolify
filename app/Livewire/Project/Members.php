<?php

namespace App\Livewire\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Gives non-admin team members access to this project: "viewer" sees it, "deployer" can also
 * deploy, start, stop and restart its resources. Members restricted to assigned projects see nothing else.
 */
class Members extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public function setAccess(int $userId, string $access): void
    {
        try {
            $this->authorize('update', $this->project);
            if (! in_array($access, ['none', 'viewer', 'deployer'], true)) {
                throw new \InvalidArgumentException('Unknown access level.');
            }

            $member = $this->project->team->members()->whereKey($userId)->wherePivot('role', 'member')->firstOrFail();
            if ($access === 'none') {
                $this->project->members()->detach($member->id);
            } else {
                $this->project->members()->syncWithoutDetaching([$member->id => ['role' => $access]]);
            }

            auditLog('ui.project_member.access_updated', [
                'team_id' => $this->project->team_id,
                'project_uuid' => $this->project->uuid,
                'member_id' => $member->id,
                'member_email' => $member->email,
                'access' => $access,
            ]);
            $this->dispatch('success', 'Project access updated.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function teamMembers(): Collection
    {
        $access = $this->project->members()->pluck('project_user.role', 'users.id');

        return $this->project->team->members()->wherePivot('role', 'member')->orderBy('email')->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'restricted' => (bool) $member->pivot->restricted_to_projects,
                'access' => $access[$member->id] ?? 'none',
            ]);
    }

    public function render()
    {
        return view('livewire.project.members', ['members' => $this->teamMembers()]);
    }
}
