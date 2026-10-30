<?php

namespace App\Livewire\Project;

use App\Models\Application;
use App\Models\Project;
use App\Models\ResourceUser;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Gives non-admin team members a role on this project and, optionally, another role on single resources:
 * "viewer" sees, "deployer" also deploys, starts, stops and restarts, "manager" also changes the
 * configuration but cannot delete. "selected" on the project shows only the resources given directly.
 * Members restricted to assigned projects see nothing else. See ChecksProjectAccess.
 */
class Members extends Component
{
    use AuthorizesRequests;

    private const PROJECT_ACCESS = ['none', 'selected', 'viewer', 'deployer', 'manager'];

    private const RESOURCE_ACCESS = ['inherit', 'none', 'viewer', 'deployer', 'manager'];

    public Project $project;

    public function setAccess(int $userId, string $access): void
    {
        try {
            $this->authorize('update', $this->project);
            if (! in_array($access, self::PROJECT_ACCESS, true)) {
                throw new \InvalidArgumentException('Unknown access level.');
            }

            $member = $this->member($userId);
            if ($access === 'none') {
                $this->project->members()->detach($member->id);
                // Without project access, resource roles would open single resources of a hidden project.
                $this->resourceRolesOf($member)->delete();
            } else {
                $this->project->members()->syncWithoutDetaching([$member->id => ['role' => $access === 'selected' ? 'none' : $access]]);
            }

            $this->audit('ui.project_member.access_updated', $member, ['access' => $access]);
            $this->dispatch('success', 'Project access updated.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function setResourceAccess(int $userId, string $resourceKey, string $access): void
    {
        try {
            $this->authorize('update', $this->project);
            if (! in_array($access, self::RESOURCE_ACCESS, true)) {
                throw new \InvalidArgumentException('Unknown access level.');
            }

            $member = $this->member($userId);
            $resource = $this->projectResources()->get($resourceKey)
                ?? throw new \InvalidArgumentException('This resource is not part of the project.');

            $match = ['user_id' => $member->id, 'resourceable_type' => $resource->getMorphClass(), 'resourceable_id' => $resource->getKey()];
            if ($access === 'inherit') {
                ResourceUser::query()->where($match)->delete();
            } else {
                ResourceUser::query()->updateOrCreate($match, ['role' => $access]);
                // A member restricted to projects needs the project itself to reach the resource.
                if ($member->pivot->restricted_to_projects && ! $this->project->members()->whereKey($member->id)->exists()) {
                    $this->project->members()->attach($member->id, ['role' => 'none']);
                }
            }

            $this->audit('ui.project_member.resource_access_updated', $member, [
                'resource_type' => class_basename($resource),
                'resource_uuid' => $resource->uuid,
                'access' => $access,
            ]);
            $this->dispatch('success', 'Resource access updated.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function member(int $userId): User
    {
        return $this->project->team->members()->whereKey($userId)->wherePivot('role', 'member')->firstOrFail();
    }

    private function audit(string $event, User $member, array $context): void
    {
        auditLog($event, [
            'team_id' => $this->project->team_id,
            'project_uuid' => $this->project->uuid,
            'member_id' => $member->id,
            'member_email' => $member->email,
            ...$context,
        ]);
    }

    /**
     * Applications, services and databases of the project, keyed "<morph class>:<id>".
     *
     * @return Collection<string, Model>
     */
    private function projectResources(): Collection
    {
        return once(fn () => $this->project->environments()->orderBy('name')->get()
            ->flatMap(fn ($environment) => $environment->applications->sortBy('name')
                ->concat($environment->services->sortBy('name'))
                ->concat($environment->databases()->sortBy('name'))
                ->each(fn (Model $resource) => $resource->setRelation('environment', $environment)))
            ->keyBy(fn (Model $resource) => $resource->getMorphClass().':'.$resource->getKey()));
    }

    private function resourceRolesOf(User $member)
    {
        $resources = $this->projectResources();

        return ResourceUser::query()->where('user_id', $member->id)->where(function ($query) use ($resources) {
            $query->whereRaw('1 = 0');
            foreach ($resources->groupBy(fn (Model $resource) => $resource->getMorphClass()) as $type => $items) {
                $query->orWhere(fn ($q) => $q->where('resourceable_type', $type)->whereIn('resourceable_id', $items->map->getKey()));
            }
        });
    }

    private function teamMembers(): Collection
    {
        $access = $this->project->members()->pluck('project_user.role', 'users.id');
        $resources = $this->projectResources();

        return $this->project->team->members()->wherePivot('role', 'member')->orderBy('email')->get()
            ->map(function (User $member) use ($access, $resources) {
                $roles = $this->resourceRolesOf($member)->get()
                    ->keyBy(fn (ResourceUser $role) => $role->resourceable_type.':'.$role->resourceable_id);
                $projectAccess = $access[$member->id] ?? 'none';

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'restricted' => (bool) $member->pivot->restricted_to_projects,
                    'access' => $projectAccess === 'none' && isset($access[$member->id]) ? 'selected' : $projectAccess,
                    'customized' => $roles->count(),
                    'resources' => $resources->map(fn (Model $resource, string $key) => [
                        'key' => $key,
                        'name' => $resource->name,
                        'type' => match (true) {
                            $resource instanceof Application => 'Application',
                            $resource instanceof Service => 'Service',
                            default => 'Database',
                        },
                        'environment' => $resource->environment->name,
                        'access' => $roles->get($key)?->role ?? 'inherit',
                    ])->values()->all(),
                ];
            });
    }

    public function render()
    {
        return view('livewire.project.members', ['members' => $this->teamMembers()]);
    }
}
