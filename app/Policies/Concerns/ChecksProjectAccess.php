<?php

namespace App\Policies\Concerns;

use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Project and resource roles for team members (fork). A member's role on a resource is the role set
 * on that resource, else their role on its project:
 * - none: hidden (the project only shows resources given to them directly)
 * - viewer: sees the resource
 * - deployer: also deploys, starts, stops and restarts it
 * - manager: also changes its configuration (environment variables, domains, settings, storage, ...),
 *   but cannot delete it
 * Members restricted to assigned projects only see those; team admins and owners keep every right.
 */
trait ChecksProjectAccess
{
    private function projectOf($resource): ?Project
    {
        if ($resource instanceof Project) {
            return $resource;
        }

        $project = $resource instanceof Environment
            ? $resource->project
            : data_get($resource, 'environment.project');

        return $project instanceof Project ? $project : null;
    }

    /**
     * The resource itself when it can carry its own role (applications, services, databases).
     */
    private function roleTargetOf($resource): ?Model
    {
        return $resource instanceof Model && ! $resource instanceof Project && ! $resource instanceof Environment
            ? $resource
            : null;
    }

    /**
     * Resources outside a project (instance databases, for example) keep the team-only rule.
     */
    private function canSeeProjectOf(User $user, $resource): bool
    {
        if (! $resource instanceof Project && ! $user->isRestrictedInTeam($this->getTeamId($resource))
            && ($resource instanceof Environment || ! $user->hasAssignedRoles())) {
            return true;
        }

        $project = $this->projectOf($resource);
        if ($project === null) {
            return true;
        }
        if ($resource instanceof Project || $resource instanceof Environment) {
            return $user->canAccessProject($project);
        }

        return ! in_array($user->effectiveRoleOn($project, $this->roleTargetOf($resource)), [null, 'none'], true);
    }

    private function canDeployProjectOf(User $user, $resource): bool
    {
        if (! $user->hasAssignedRoles()) {
            return false;
        }

        return $user->canDeployProject($this->projectOf($resource), $this->roleTargetOf($resource));
    }

    /**
     * Whether a manager may change the resource's configuration (team admins are checked by each policy).
     */
    private function canManageProjectOf(User $user, $resource): bool
    {
        if (! $user->hasAssignedRoles()) {
            return false;
        }

        return $user->canManageProjectResource($this->projectOf($resource), $this->roleTargetOf($resource));
    }
}
