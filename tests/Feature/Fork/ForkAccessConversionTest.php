<?php

use App\Enums\AccessLevel;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Fork: the migration that turns the former fork project and resource roles into Coolify access grants.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array', 'app.maintenance.store' => 'array']);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    Server::flushIdentityMap();
    Cache::flush();

    $this->team = Team::factory()->create(['show_boarding' => false]);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $this->shop = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'shop']);
    $shopProd = Environment::factory()->create(['project_id' => $this->shop->id, 'name' => 'prod']);
    $this->blog = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'blog']);
    $blogProd = Environment::factory()->create(['project_id' => $this->blog->id, 'name' => 'prod']);

    $app = fn (Environment $environment, string $name) => Application::factory()->create([
        'name' => $name,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->shopApi = $app($shopProd, 'shop-api');
    $this->shopAdmin = $app($shopProd, 'shop-admin');
    $this->blogWeb = $app($blogProd, 'blog-web');
});

function forkMember(Team $team, bool $restricted): User
{
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'member']);
    DB::table('team_user')->where('team_id', $team->id)->where('user_id', $user->id)
        ->update(['restricted_to_projects' => $restricted]);

    return $user;
}

function forkProjectRole(User $user, Project $project, string $role): void
{
    DB::table('project_user')->insert(['project_id' => $project->id, 'user_id' => $user->id, 'role' => $role]);
}

function forkResourceRole(User $user, Application $application, string $role): void
{
    DB::table('resource_user')->insert([
        'user_id' => $user->id,
        'resourceable_type' => $application->getMorphClass(),
        'resourceable_id' => $application->id,
        'role' => $role,
    ]);
}

function runForkAccessConversion(): void
{
    (require database_path('migrations/2026_11_16_000000_convert_fork_project_roles_to_access_grants.php'))->up();
    app(AccessResolver::class)->flush();
}

function storedAccess(Team $team, User $user): ?array
{
    $access = DB::table('team_user')->where('team_id', $team->id)->where('user_id', $user->id)->value('access');

    return $access === null ? null : json_decode($access, true);
}

function allowsFor(User $user, AccessLevel $level, $target): bool
{
    return app(AccessResolver::class)->allows($user->fresh(), $level, $target);
}

test('a restricted deployer gets deploy on the project only', function () {
    $student = forkMember($this->team, restricted: true);
    forkProjectRole($student, $this->shop, 'deployer');

    runForkAccessConversion();

    expect(storedAccess($this->team, $student))->toBe([
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['deploy']],
    ])
        ->and(allowsFor($student, AccessLevel::Deploy, $this->shopApi))->toBeTrue()
        ->and(allowsFor($student, AccessLevel::Write, $this->shopApi))->toBeFalse()
        ->and(allowsFor($student, AccessLevel::Read, $this->blogWeb))->toBeFalse();
});

test('a manager gets write but not delete', function () {
    $student = forkMember($this->team, restricted: true);
    forkProjectRole($student, $this->shop, 'manager');

    runForkAccessConversion();

    expect(allowsFor($student, AccessLevel::Write, $this->shopApi))->toBeTrue()
        ->and(allowsFor($student, AccessLevel::Deploy, $this->shopApi))->toBeTrue()
        ->and(allowsFor($student, AccessLevel::Delete, $this->shopApi))->toBeFalse();
});

test('a lower role on one resource splits the project into resource grants', function () {
    $student = forkMember($this->team, restricted: true);
    forkProjectRole($student, $this->shop, 'manager');
    forkResourceRole($student, $this->shopAdmin, 'none');

    runForkAccessConversion();

    expect(storedAccess($this->team, $student))->toBe([
        ['scope' => 'resource', 'uuid' => $this->shopApi->uuid, 'levels' => ['write']],
    ])
        ->and(allowsFor($student, AccessLevel::Write, $this->shopApi))->toBeTrue()
        ->and(allowsFor($student, AccessLevel::Read, $this->shopAdmin))->toBeFalse();
});

test('a higher role on one resource is added on top of the project grant', function () {
    $student = forkMember($this->team, restricted: true);
    forkProjectRole($student, $this->shop, 'viewer');
    forkResourceRole($student, $this->shopApi, 'deployer');

    runForkAccessConversion();

    expect(storedAccess($this->team, $student))->toBe([
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']],
        ['scope' => 'resource', 'uuid' => $this->shopApi->uuid, 'levels' => ['deploy']],
    ])
        ->and(allowsFor($student, AccessLevel::Deploy, $this->shopApi))->toBeTrue()
        ->and(allowsFor($student, AccessLevel::Deploy, $this->shopAdmin))->toBeFalse();
});

test('a member who was not restricted keeps team read and gets their project role on top', function () {
    $member = forkMember($this->team, restricted: false);
    forkProjectRole($member, $this->blog, 'deployer');

    runForkAccessConversion();

    expect(storedAccess($this->team, $member))->toBe([
        ['scope' => 'team', 'uuid' => null, 'levels' => ['read']],
        ['scope' => 'project', 'uuid' => $this->blog->uuid, 'levels' => ['deploy']],
    ])
        ->and(allowsFor($member, AccessLevel::Read, $this->shopApi))->toBeTrue()
        ->and(allowsFor($member, AccessLevel::Deploy, $this->blogWeb))->toBeTrue()
        ->and(allowsFor($member, AccessLevel::Deploy, $this->shopApi))->toBeFalse();
});

test('a restricted member without projects sees nothing', function () {
    $student = forkMember($this->team, restricted: true);

    runForkAccessConversion();

    expect(storedAccess($this->team, $student))->toBe([])
        ->and(allowsFor($student, AccessLevel::Read, $this->shopApi))->toBeFalse();
});

test('members without fork roles, admins and members with access already set are left alone', function () {
    $plain = forkMember($this->team, restricted: false);
    $admin = User::factory()->create();
    $this->team->members()->attach($admin->id, ['role' => 'admin', 'restricted_to_projects' => true]);
    $configured = forkMember($this->team, restricted: true);
    forkProjectRole($configured, $this->shop, 'viewer');
    $kept = [['scope' => 'project', 'uuid' => $this->blog->uuid, 'levels' => ['write']]];
    DB::table('team_user')->where('user_id', $configured->id)->update(['access' => json_encode($kept)]);

    runForkAccessConversion();

    expect(storedAccess($this->team, $plain))->toBeNull()
        ->and(storedAccess($this->team, $admin))->toBeNull()
        ->and(storedAccess($this->team, $configured))->toBe($kept);
});

test('a pending invitation limited to a project gets a grant on that project', function () {
    $invitation = TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => 'fork-project-invitation',
        'email' => 'student@school.test',
        'role' => 'member',
        'link' => 'https://example.test/invite/fork-project-invitation',
        'via' => 'link',
    ]);
    DB::table('team_invitations')->where('id', $invitation->id)
        ->update(['project_id' => $this->shop->id, 'project_role' => 'deployer']);

    runForkAccessConversion();

    expect($invitation->fresh()->access)->toBe([
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['deploy']],
    ]);
});
