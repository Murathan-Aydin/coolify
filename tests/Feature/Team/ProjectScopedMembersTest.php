<?php

use App\Livewire\Project\Members;
use App\Livewire\Team\Member;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
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
