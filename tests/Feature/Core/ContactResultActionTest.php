<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Jobs\AddTagToContactResultChunkJob;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactResultActionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactResultActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_contact_result_actions_follow_contact_capabilities(): void
    {
        $manager = User::factory()->create();
        $member = User::factory()->create();
        $viewer = User::factory()->create();

        $this->profile($manager, 'manager');
        $this->profile($member, 'member');
        $this->profile($viewer, 'viewer');

        $registry = app(ContactResultActionRegistry::class);

        $managerKeys = array_column($registry->actionsFor($manager), 'key');
        $memberKeys = array_column($registry->actionsFor($member), 'key');
        $viewerKeys = array_column($registry->actionsFor($viewer), 'key');

        $this->assertContains('core.add_tag', $managerKeys);
        $this->assertContains('core.export', $managerKeys);
        $this->assertContains('core.add_tag', $memberKeys);
        $this->assertNotContains('core.export', $memberKeys);
        $this->assertNotContains('core.add_tag', $viewerKeys);
        $this->assertNotContains('core.export', $viewerKeys);
    }

    public function test_bulk_tag_action_freezes_only_current_visible_contact_ids(): void
    {
        Queue::fake();

        $member = User::factory()->create();
        $other = User::factory()->create();
        $this->profile($member, 'member');

        $visible = Contact::factory()->create([
            'assigned_user_id' => $member->getKey(),
            'email' => 'visible@example.test',
        ]);
        Contact::factory()->create([
            'assigned_user_id' => $other->getKey(),
            'email' => 'hidden@example.test',
        ]);

        $this
            ->actingAs($member)
            ->post(route('crm.contacts.results.tag'), [
                'contact_result' => [
                    'search' => '',
                    'criteria' => [],
                ],
                'tag' => 'Follow Up',
            ])
            ->assertRedirect(route('crm.contacts.index'));

        Queue::assertPushed(
            AddTagToContactResultChunkJob::class,
            fn (AddTagToContactResultChunkJob $job): bool => $job->contactIds === [$visible->getKey()]
                && $job->tag === 'Follow Up'
                && $job->actorUserId === $member->getKey(),
        );
    }

    public function test_export_contains_only_visible_contacts_from_the_result_set(): void
    {
        $manager = User::factory()->create();
        $other = User::factory()->create();
        $this->profile($manager, 'manager');

        Contact::factory()->create([
            'assigned_user_id' => $manager->getKey(),
            'email' => 'direct@example.test',
        ]);
        Contact::factory()->create([
            'assigned_user_id' => null,
            'assigned_team_id' => null,
            'email' => 'unassigned@example.test',
        ]);
        Contact::factory()->create([
            'assigned_user_id' => $other->getKey(),
            'email' => 'hidden@example.test',
        ]);

        $response = $this
            ->actingAs($manager)
            ->post(route('crm.contacts.results.export'), [
                'contact_result' => [
                    'search' => '',
                    'criteria' => [],
                ],
            ]);

        $response->assertOk();
        $response->assertDownload();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('direct@example.test', $csv);
        $this->assertStringContainsString('unassigned@example.test', $csv);
        $this->assertStringNotContainsString('hidden@example.test', $csv);
    }


    public function test_stale_or_invalid_result_criteria_do_not_broaden_bulk_actions(): void
    {
        Queue::fake();

        $member = User::factory()->create();
        $this->profile($member, 'member');
        Contact::factory()->create([
            'assigned_user_id' => $member->getKey(),
            'source' => 'known-source',
        ]);

        $this
            ->actingAs($member)
            ->post(route('crm.contacts.results.tag'), [
                'contact_result' => [
                    'search' => '',
                    'criteria' => [
                        'source' => ['missing-source'],
                    ],
                ],
                'tag' => 'Should Not Apply',
            ])
            ->assertSessionHasErrors('contact_result');

        Queue::assertNotPushed(AddTagToContactResultChunkJob::class);
    }

    private function profile(User $user, string $role): UserAccessProfile
    {
        return UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => $role,
            'is_active' => true,
            'capability_overrides' => null,
        ]);
    }
}