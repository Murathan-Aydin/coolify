<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fork: Coolify 4.7 brought its own access grants (team_user.access), which replace the fork's project and
 * resource roles (project_user, resource_user, team_user.restricted_to_projects, team_invitations.project_id).
 * This converts those roles into grants: viewer → read, deployer → deploy, manager → write (no delete).
 *
 * Grants only add rights, so a lower role on one resource (or "none") cannot sit under a project grant. Such a
 * project is converted resource by resource instead; resources created later in it are then not granted.
 * A member who was not restricted keeps team-wide read, so "none" cannot hide anything from them: that case
 * is logged as a warning in laravel.log.
 *
 * The old tables and columns are kept, unused, so the result can be checked. Members whose access was
 * already set (team_user.access not null) are left alone.
 */
return new class extends Migration
{
    private const LEVELS = ['viewer' => 'read', 'deployer' => 'deploy', 'manager' => 'write'];

    private const RANK = ['none' => 0, 'viewer' => 1, 'deployer' => 2, 'manager' => 3];

    private const RESOURCE_TABLES = [
        'App\Models\Application' => 'applications',
        'App\Models\Service' => 'services',
        'App\Models\StandalonePostgresql' => 'standalone_postgresqls',
        'App\Models\StandaloneMysql' => 'standalone_mysqls',
        'App\Models\StandaloneMariadb' => 'standalone_mariadbs',
        'App\Models\StandaloneMongodb' => 'standalone_mongodbs',
        'App\Models\StandaloneRedis' => 'standalone_redis',
        'App\Models\StandaloneKeydb' => 'standalone_keydbs',
        'App\Models\StandaloneDragonfly' => 'standalone_dragonflies',
        'App\Models\StandaloneClickhouse' => 'standalone_clickhouses',
        'App\Models\StandaloneSqlite' => 'standalone_sqlites',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('project_user') || ! Schema::hasColumn('team_user', 'access')) {
            return;
        }

        $this->convertMembers();
        $this->convertInvitations();
    }

    public function down(): void
    {
        // The grants stay: the fork roles they came from are still in their tables.
    }

    private function convertMembers(): void
    {
        $restrictedColumn = Schema::hasColumn('team_user', 'restricted_to_projects');
        $memberships = DB::table('team_user')->where('role', 'member')->whereNull('access')->get();

        foreach ($memberships as $membership) {
            $restricted = $restrictedColumn && (bool) $membership->restricted_to_projects;
            $projectRoles = DB::table('project_user')
                ->join('projects', 'projects.id', '=', 'project_user.project_id')
                ->where('project_user.user_id', $membership->user_id)
                ->where('projects.team_id', $membership->team_id)
                ->pluck('project_user.role', 'projects.id')
                ->all();
            $resourceRoles = $this->resourceRoles($membership->user_id, $membership->team_id);

            // Nothing set by the fork: Coolify's default for members (team-wide read) applies.
            if (! $restricted && $projectRoles === [] && $resourceRoles === []) {
                continue;
            }

            $grants = $restricted ? [] : [['scope' => 'team', 'uuid' => null, 'levels' => ['read']]];
            // Lowest role a member has anywhere: none when restricted, read through the team grant otherwise.
            $floor = $restricted ? 'none' : 'viewer';

            foreach (array_unique([...array_keys($projectRoles), ...array_keys($resourceRoles)]) as $projectId) {
                $projectUuid = DB::table('projects')->where('id', $projectId)->value('uuid');
                $base = $this->role($projectRoles[$projectId] ?? null) ?? $floor;
                $overrides = array_map(fn ($role) => $this->role($role) ?? $base, $resourceRoles[$projectId] ?? []);
                $context = "member {$membership->user_id} of team {$membership->team_id}, project {$projectUuid}";

                if (self::RANK[$base] < self::RANK[$floor]) {
                    Log::warning("Fork access conversion: {$context} had no access to the project, but keeps team-wide read.");
                }

                $lowered = array_filter($overrides, fn (string $role) => self::RANK[$role] < self::RANK[$base]);
                if ($lowered === []) {
                    if (self::RANK[$base] > self::RANK[$floor]) {
                        $grants[] = ['scope' => 'project', 'uuid' => $projectUuid, 'levels' => [self::LEVELS[$base]]];
                    }
                    foreach ($overrides as $uuid => $role) {
                        if (self::RANK[$role] > max(self::RANK[$base], self::RANK[$floor])) {
                            $grants[] = ['scope' => 'resource', 'uuid' => $uuid, 'levels' => [self::LEVELS[$role]]];
                        }
                    }

                    continue;
                }

                Log::info("Fork access conversion: {$context} is granted resource by resource, because some resources had a lower role.");
                foreach ($this->projectResourceUuids($projectId) as $uuid) {
                    $role = $overrides[$uuid] ?? $base;
                    if (self::RANK[$role] < self::RANK[$floor]) {
                        Log::warning("Fork access conversion: {$context} had no access to resource {$uuid}, but keeps team-wide read.");
                    } elseif (self::RANK[$role] > self::RANK[$floor]) {
                        $grants[] = ['scope' => 'resource', 'uuid' => $uuid, 'levels' => [self::LEVELS[$role]]];
                    }
                }
            }

            DB::table('team_user')->where('id', $membership->id)->update(['access' => json_encode($grants)]);
            Log::info("Fork access conversion: member {$membership->user_id} of team {$membership->team_id} now has ".json_encode($grants));
        }
    }

    /**
     * Pending invitations limited to a project become invitations with a grant on that project only.
     */
    private function convertInvitations(): void
    {
        if (! Schema::hasColumn('team_invitations', 'project_id') || ! Schema::hasColumn('team_invitations', 'access')) {
            return;
        }

        $invitations = DB::table('team_invitations')
            ->join('projects', 'projects.id', '=', 'team_invitations.project_id')
            ->where('team_invitations.role', 'member')
            ->whereNull('team_invitations.access')
            ->get(['team_invitations.id', 'team_invitations.project_role', 'projects.uuid as project_uuid']);

        foreach ($invitations as $invitation) {
            $level = self::LEVELS[$this->role($invitation->project_role) ?? 'viewer'] ?? 'read';
            DB::table('team_invitations')->where('id', $invitation->id)->update([
                'access' => json_encode([['scope' => 'project', 'uuid' => $invitation->project_uuid, 'levels' => [$level]]]),
            ]);
        }
    }

    /**
     * The member's roles on single resources of the team, by project ID and resource UUID.
     *
     * @return array<int, array<string, string>>
     */
    private function resourceRoles(int $userId, int $teamId): array
    {
        if (! Schema::hasTable('resource_user')) {
            return [];
        }

        $roles = [];
        foreach (DB::table('resource_user')->where('user_id', $userId)->get() as $row) {
            $table = self::RESOURCE_TABLES[$row->resourceable_type] ?? null;
            $resource = $table === null ? null : DB::table($table)
                ->join('environments', 'environments.id', '=', "{$table}.environment_id")
                ->join('projects', 'projects.id', '=', 'environments.project_id')
                ->where("{$table}.id", $row->resourceable_id)
                ->where('projects.team_id', $teamId)
                ->first(["{$table}.uuid", 'projects.id as project_id']);
            if ($resource !== null) {
                $roles[$resource->project_id][$resource->uuid] = $row->role;
            }
        }

        return $roles;
    }

    /**
     * @return list<string>
     */
    private function projectResourceUuids(int $projectId): array
    {
        $uuids = [];
        foreach (self::RESOURCE_TABLES as $table) {
            $uuids = [...$uuids, ...DB::table($table)
                ->join('environments', 'environments.id', '=', "{$table}.environment_id")
                ->where('environments.project_id', $projectId)
                ->whereNull("{$table}.deleted_at")
                ->pluck("{$table}.uuid")
                ->all()];
        }

        return $uuids;
    }

    private function role(?string $role): ?string
    {
        return $role !== null && isset(self::RANK[$role]) ? $role : null;
    }
};
