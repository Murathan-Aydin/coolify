<?php

use App\Livewire\Project\Members;
use App\Livewire\Team\Member;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ResourceUser;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->student = User::factory()->create();
    $this->team->members()->attach($this->student->id, ['role' => 'member', 'restricted_to_projects' => true]);

    $this->projectA = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'A']);
    $this->projectB = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'B']);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    $this->appA = Application::factory()->create([
        'environment_id' => $this->projectA->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
    $this->appB = Application::factory()->create([
        'environment_id' => $this->projectB->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $this->student->projects()->attach($this->projectA->id, ['role' => 'viewer']);
});

test('a restricted member only lists the projects assigned to them', function () {
    $this->actingAs($this->student);
    session(['currentTeam' => $this->team]);

    expect(Project::ownedByCurrentTeam()->pluck('id')->all())->toBe([$this->projectA->id]);
});

test('a restricted member cannot open resources of an unassigned project', function () {
    $student = $this->student->fresh();

    expect(Gate::forUser($student)->allows('view', $this->projectA))->toBeTrue()
        ->and(Gate::forUser($student)->allows('view', $this->appA))->toBeTrue()
        ->and(Gate::forUser($student)->allows('view', $this->projectB))->toBeFalse()
        ->and(Gate::forUser($student)->allows('view', $this->appB))->toBeFalse();
});

test('a viewer cannot deploy and a deployer can deploy but not delete', function () {
    expect(Gate::forUser($this->student->fresh())->allows('deploy', $this->appA))->toBeFalse();

    $this->student->projects()->updateExistingPivot($this->projectA->id, ['role' => 'deployer']);
    $student = $this->student->fresh();

    expect(Gate::forUser($student)->allows('deploy', $this->appA))->toBeTrue()
        ->and(Gate::forUser($student)->allows('deploy', $this->appB))->toBeFalse()
        ->and(Gate::forUser($student)->allows('delete', $this->appA))->toBeFalse()
        ->and(Gate::forUser($student)->allows('update', $this->appA))->toBeFalse();
});

test('unrestricted members and owners keep seeing every project', function () {
    $this->team->members()->updateExistingPivot($this->student->id, ['restricted_to_projects' => false]);

    expect(Gate::forUser($this->student->fresh())->allows('view', $this->projectB))->toBeTrue()
        ->and(Gate::forUser($this->owner->fresh())->allows('view', $this->appB))->toBeTrue()
        ->and(Gate::forUser($this->owner->fresh())->allows('deploy', $this->appB))->toBeTrue();
});

test('admins assign project access from the project settings', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire\Livewire::test(Members::class, ['project' => $this->projectB])
        ->assertSee($this->student->email)
        ->call('setAccess', $this->student->id, 'deployer')
        ->assertDispatched('success');

    expect(Gate::forUser($this->student->fresh())->allows('deploy', $this->appB))->toBeTrue();

    Livewire\Livewire::test(Members::class, ['project' => $this->projectB])
        ->call('setAccess', $this->student->id, 'none');

    expect(Gate::forUser($this->student->fresh())->allows('view', $this->appB))->toBeFalse();
});

test('members cannot change project access', function () {
    $this->student->projects()->updateExistingPivot($this->projectA->id, ['role' => 'deployer']);
    $this->actingAs($this->student);
    session(['currentTeam' => $this->team]);

    Livewire\Livewire::test(Members::class, ['project' => $this->projectA])
        ->call('setAccess', $this->student->id, 'deployer')
        ->assertNotDispatched('success');
});

test('admins toggle the project restriction from the team members list', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire\Livewire::test(Member::class, ['member' => $this->student])
        ->call('setProjectRestriction', false);

    expect($this->student->fresh()->isRestrictedInTeam($this->team->id))->toBeFalse();
});

test('a restricted member cannot open team-wide pages', function () {
    $this->actingAs($this->student);
    session(['currentTeam' => $this->team]);
    $server = Server::query()->where('team_id', $this->team->id)->firstOrFail();

    foreach ([
        route('server.index'),
        route('server.show', ['server_uuid' => $server->uuid]),
        route('server.proxy.certificates', ['server_uuid' => $server->uuid]),
        route('server.resources', ['server_uuid' => $server->uuid]),
        route('security.private-key.index'),
        route('source.all'),
        route('shared-variables.team.index'),
        route('notifications.email'),
        route('team.member.index'),
        route('tags.show'),
        route('dashboard.canvas'),
        '/canvas/api/servers',
    ] as $url) {
        $this->get($url)->assertForbidden();
    }

    expect(Gate::forUser($this->student->fresh())->allows('view', $server))->toBeFalse();
});

test('a restricted member still opens the dashboard and assigned projects', function () {
    $this->actingAs($this->student);
    session(['currentTeam' => $this->team]);

    $this->get(route('dashboard'))->assertOk()->assertDontSee($this->projectB->name.'</');
    $this->get(route('project.show', ['project_uuid' => $this->projectA->uuid]))->assertOk();
});

test('owners keep opening team-wide pages', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $this->get(route('server.index'))->assertOk();
});

test('a manager edits and deploys project resources but cannot delete them', function () {
    $this->student->projects()->updateExistingPivot($this->projectA->id, ['role' => 'manager']);
    $student = $this->student->fresh();

    expect(Gate::forUser($student)->allows('update', $this->appA))->toBeTrue()
        ->and(Gate::forUser($student)->allows('manageEnvironment', $this->appA))->toBeTrue()
        ->and(Gate::forUser($student)->allows('deploy', $this->appA))->toBeTrue()
        ->and(Gate::forUser($student)->allows('delete', $this->appA))->toBeFalse()
        ->and(Gate::forUser($student)->allows('delete', $this->projectA))->toBeFalse()
        ->and(Gate::forUser($student)->allows('update', $this->appB))->toBeFalse();
});

test('a resource role overrides the project role', function () {
    $appA2 = Application::factory()->create([
        'environment_id' => $this->appA->environment_id,
        'destination_id' => $this->appA->destination_id,
        'destination_type' => StandaloneDocker::class,
    ]);
    ResourceUser::create(['user_id' => $this->student->id, 'resourceable_type' => $this->appA->getMorphClass(), 'resourceable_id' => $this->appA->id, 'role' => 'manager']);
    ResourceUser::create(['user_id' => $this->student->id, 'resourceable_type' => $appA2->getMorphClass(), 'resourceable_id' => $appA2->id, 'role' => 'none']);
    $student = $this->student->fresh();

    expect(Gate::forUser($student)->allows('update', $this->appA))->toBeTrue()
        ->and(Gate::forUser($student)->allows('view', $appA2))->toBeFalse()
        ->and(Gate::forUser($student)->allows('view', $this->projectA))->toBeTrue();
});

test('selected resources only shows just the resources given directly', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
    $appB2 = Application::factory()->create([
        'environment_id' => $this->appB->environment_id,
        'destination_id' => $this->appB->destination_id,
        'destination_type' => StandaloneDocker::class,
    ]);

    Livewire\Livewire::test(Members::class, ['project' => $this->projectB])
        ->call('setResourceAccess', $this->student->id, $appB2->getMorphClass().':'.$appB2->id, 'manager')
        ->assertDispatched('success');

    $student = $this->student->fresh();
    expect(Gate::forUser($student)->allows('view', $this->projectB))->toBeTrue()
        ->and(Gate::forUser($student)->allows('update', $appB2))->toBeTrue()
        ->and(Gate::forUser($student)->allows('view', $this->appB))->toBeFalse();

    Livewire\Livewire::test(Members::class, ['project' => $this->projectB])
        ->call('setAccess', $this->student->id, 'none');

    expect(Gate::forUser($this->student->fresh())->allows('view', $appB2))->toBeFalse()
        ->and(ResourceUser::query()->where('user_id', $this->student->id)->count())->toBe(0);
});

test('resources of another project cannot be assigned from a project', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire\Livewire::test(Members::class, ['project' => $this->projectA])
        ->call('setResourceAccess', $this->student->id, $this->appB->getMorphClass().':'.$this->appB->id, 'manager')
        ->assertNotDispatched('success');

    expect(ResourceUser::query()->count())->toBe(0);
});
