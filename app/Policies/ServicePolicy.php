<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;
use App\Policies\Concerns\ChecksProjectAccess;

class ServicePolicy
{
    use ChecksProjectAccess;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Service $service): bool
    {
        $teamId = $this->getTeamId($service);

        return $teamId !== null && $user->teams->contains('id', $teamId) && $this->canSeeProjectOf($user, $service);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Service $service): bool
    {
        $teamId = $this->getTeamId($service);

        return $teamId !== null && ($user->isAdminOfTeam($teamId) || $this->canManageProjectOf($user, $service));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Service $service): bool
    {
        $teamId = $this->getTeamId($service);

        return $teamId !== null && $user->isAdminOfTeam($teamId);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Service $service): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Service $service): bool
    {
        return false;
    }

    /**
     * Determine whether the user can stop the service.
     */
    public function stop(User $user, Service $service): bool
    {
        $teamId = $this->getTeamId($service);

        return $teamId !== null && ($user->isAdminOfTeam($teamId) || $this->canDeployProjectOf($user, $service));
    }

    /**
     * Determine whether the user can manage environment variables.
     */
    public function manageEnvironment(User $user, Service $service): bool
    {
        $teamId = $this->getTeamId($service);

        return $teamId !== null && ($user->isAdminOfTeam($teamId) || $this->canManageProjectOf($user, $service));
    }

    /**
     * Determine whether the user can upload a backup archive for a database within this service.
     */
    public function uploadBackup(User $user, Service $service): bool
    {
        $teamId = $this->getTeamId($service);

        return $teamId !== null && ($user->isAdminOfTeam($teamId) || $this->canManageProjectOf($user, $service));
    }

    /**
     * Determine whether the user can deploy the service.
     */
    public function deploy(User $user, Service $service): bool
    {
        $teamId = $this->getTeamId($service);

        return $teamId !== null && ($user->isAdminOfTeam($teamId) || $this->canDeployProjectOf($user, $service));
    }

    private function getTeamId(Service $service): ?int
    {
        return $service->team()?->id;
    }
}
