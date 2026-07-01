<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastRecipientContactSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.modules.broadcasts.enabled' => true,
        ]);
    }

    public function test_it_searches_contacts_by_name_for_broadcast_recipient_selection(): void
    {
        $user = User::factory()->create();

        $match = Contact::factory()->create([
            'name' => 'Jane Lead',
            'email' => 'jane@example.test',
            'phone' => '5551112222',
        ]);

        Contact::factory()->create([
            'name' => 'Robert Client',
            'email' => 'robert@example.test',
            'phone' => '5553334444',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson(route('crm.broadcasts.recipient-contacts.search', [
                'q' => 'Jane',
            ]));

        $response->assertOk();
        $response->assertJsonPath('contacts.0.id', $match->id);
        $response->assertJsonPath('contacts.0.label', 'Jane Lead — jane@example.test');
        $response->assertJsonCount(1, 'contacts');
    }

    public function test_it_searches_contacts_by_email_for_broadcast_recipient_selection(): void
    {
        $user = User::factory()->create();

        $match = Contact::factory()->create([
            'name' => 'Jane Lead',
            'email' => 'jane@example.test',
        ]);

        Contact::factory()->create([
            'name' => 'Robert Client',
            'email' => 'robert@example.test',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson(route('crm.broadcasts.recipient-contacts.search', [
                'q' => 'jane@example',
            ]));

        $response->assertOk();
        $response->assertJsonPath('contacts.0.id', $match->id);
        $response->assertJsonPath('contacts.0.email', 'jane@example.test');
        $response->assertJsonCount(1, 'contacts');
    }

    public function test_it_searches_contacts_by_phone_for_broadcast_recipient_selection(): void
    {
        $user = User::factory()->create();

        $match = Contact::factory()->create([
            'name' => 'Jane Lead',
            'email' => 'jane@example.test',
            'phone' => '5551112222',
        ]);

        Contact::factory()->create([
            'name' => 'Robert Client',
            'email' => 'robert@example.test',
            'phone' => '5553334444',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson(route('crm.broadcasts.recipient-contacts.search', [
                'q' => '1112222',
            ]));

        $response->assertOk();
        $response->assertJsonPath('contacts.0.id', $match->id);
        $response->assertJsonPath('contacts.0.phone', '5551112222');
        $response->assertJsonCount(1, 'contacts');
    }

    public function test_it_returns_empty_results_for_empty_search(): void
    {
        $user = User::factory()->create();

        Contact::factory()->create();

        $response = $this
            ->actingAs($user)
            ->getJson(route('crm.broadcasts.recipient-contacts.search'));

        $response->assertOk();
        $response->assertExactJson([
            'contacts' => [],
        ]);
    }

    public function test_it_limits_contact_search_results(): void
    {
        $user = User::factory()->create();

        Contact::factory()
            ->count(25)
            ->create([
                'name' => 'Shared Name',
            ]);

        $response = $this
            ->actingAs($user)
            ->getJson(route('crm.broadcasts.recipient-contacts.search', [
                'q' => 'Shared',
            ]));

        $response->assertOk();
        $response->assertJsonCount(20, 'contacts');
    }
}