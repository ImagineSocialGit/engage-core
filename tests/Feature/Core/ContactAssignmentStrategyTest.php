<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Jobs\AssignContactResultChunkJob;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactAssignmentStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_result_round_robin_assigns_only_unassigned_contacts(): void
    {
        $actor = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $team = Team::query()->create(['name' => 'Sales', 'is_active' => true]);
        $team->users()->attach([$first->getKey(), $second->getKey()]);
        $existing = User::factory()->create();
        $contacts = Contact::factory()->count(3)->create();
        $contacts[2]->forceFill(['assigned_user_id' => $existing->getKey()])->save();

        $job = new AssignContactResultChunkJob(
            contactIds: $contacts->modelKeys(),
            mode: 'team_round_robin',
            assignedUserId: null,
            assignedTeamId: (int) $team->getKey(),
            onlyUnassigned: true,
            actorUserId: (int) $actor->getKey(),
            operationId: 'test-contact-assignment',
        );
        app()->call([$job, 'handle']);

        $this->assertSame((int) $first->getKey(), $contacts[0]->refresh()->assigned_user_id);
        $this->assertSame((int) $second->getKey(), $contacts[1]->refresh()->assigned_user_id);
        $this->assertSame((int) $existing->getKey(), $contacts[2]->refresh()->assigned_user_id);
        $this->assertSame((int) $team->getKey(), $contacts[0]->assigned_team_id);
        $this->assertSame((int) $team->getKey(), $contacts[1]->assigned_team_id);
    }
}