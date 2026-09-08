<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Access\BroadcastsAccessCapabilityContributor;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactResultBroadcastActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_carry_criteria_result_set_into_broadcast_builder(): void
    {
        $owner = User::factory()->create();
        Contact::factory()->create([
            'source' => 'referral',
        ]);

        $response = $this
            ->actingAs($owner)
            ->post(route('crm.broadcasts.from-contact-results'), [
                'contact_result' => [
                    'search' => '',
                    'criteria' => [
                        'source' => ['referral'],
                    ],
                ],
            ]);

        $response
            ->assertRedirect(route('crm.broadcasts.index'))
            ->assertSessionHasInput('recipient_filter_type', 'criteria')
            ->assertSessionHasInput('recipient_criteria', [
                'source' => ['referral'],
            ]);
    }

    public function test_search_result_is_materialized_to_visible_contact_ids_for_broadcast(): void
    {
        $manager = User::factory()->create();
        $other = User::factory()->create();
        $this->profile($manager, [
            BroadcastsAccessCapabilityContributor::CREATE_FROM_CONTACT_RESULTS => true,
        ]);

        $visible = Contact::factory()->create([
            'assigned_user_id' => $manager->getKey(),
            'name' => 'Matching Visible',
            'email' => 'visible@example.test',
        ]);
        Contact::factory()->create([
            'assigned_user_id' => $other->getKey(),
            'name' => 'Matching Hidden',
            'email' => 'hidden@example.test',
        ]);
        Contact::factory()->create([
            'assigned_user_id' => $manager->getKey(),
            'name' => 'Different Person',
            'email' => 'different@example.test',
        ]);

        $response = $this
            ->actingAs($manager)
            ->post(route('crm.broadcasts.from-contact-results'), [
                'contact_result' => [
                    'search' => 'Matching',
                    'criteria' => [],
                ],
            ]);

        $response
            ->assertRedirect(route('crm.broadcasts.index'))
            ->assertSessionHasInput('recipient_filter_type', 'contact_ids')
            ->assertSessionHasInput('contact_ids', [$visible->getKey()]);
    }

    public function test_user_without_broadcast_result_capability_is_forbidden(): void
    {
        $member = User::factory()->create();
        UserAccessProfile::query()->create([
            'user_id' => $member->getKey(),
            'role_key' => 'member',
            'is_active' => true,
            'capability_overrides' => null,
        ]);

        $this
            ->actingAs($member)
            ->post(route('crm.broadcasts.from-contact-results'), [
                'contact_result' => [
                    'search' => '',
                    'criteria' => [],
                ],
            ])
            ->assertForbidden();
    }

    /** @param array<string, bool> $overrides */
    private function profile(User $user, array $overrides): UserAccessProfile
    {
        return UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => 'manager',
            'is_active' => true,
            'capability_overrides' => $overrides,
        ]);
    }
}