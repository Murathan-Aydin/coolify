<?php

namespace App\Policies\Concerns;

use App\Models\Environment;
use App\Models\Project;
use App\Models\User;

/**
 * Members restricted to some projects only see those, and the "deployer" project role lets
 * a non-admin member run lifecycle actions (deploy, start, stop, restart) on them.
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
     * Resources outside a project (instance databases, for example) keep the team-only rule.
     */
    private function canSeeProjectOf(User $user, $resource): bool
    {
        if (! $resource instanceof Project && ! $user->isRestrictedInTeam($this->getTeamId($resource))) {
            return true;
        }

        $project = $this->projectOf($resource);

        return $project === null || $user->canAccessProject($project);
    }

    private function canDeployProjectOf(User $user, $resource): bool
    {
        if (! $user->hasDeployerProjects()) {
            return false;
        }

        $project = $this->projectOf($resource);

        return $project !== null && $user->canDeployProject($project);
    }
}
