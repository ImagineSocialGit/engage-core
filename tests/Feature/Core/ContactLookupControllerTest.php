<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactLookupControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_searches_contacts_by_name(): void
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
            ->getJson(route('crm.contacts.lookup', [
                'q' => 'Jane',
            ]));

        $response->assertOk();
        $response->assertJsonPath('contacts.0.id', $match->id);
        $response->assertJsonPath('contacts.0.label', 'Jane Lead — jane@example.test');
        $response->assertJsonCount(1, 'contacts');
    }

    public function test_it_searches_contacts_by_email(): void
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
            ->getJson(route('crm.contacts.lookup', [
                'q' => 'jane@example',
            ]));

        $response->assertOk();
        $response->assertJsonPath('contacts.0.id', $match->id);
        $response->assertJsonPath('contacts.0.email', 'jane@example.test');
        $response->assertJsonCount(1, 'contacts');
    }

    public function test_it_searches_contacts_by_phone(): void
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
            ->getJson(route('crm.contacts.lookup', [
                'q' => '1112222',
            ]));

        $response->assertOk();
        $response->assertJsonPath('contacts.0.id', $match->id);
        $response->assertJsonPath('contacts.0.phone', '5551112222');
        $response->assertJsonCount(1, 'contacts');
    }

    public function test_it_returns_contacts_by_ids(): void
    {
        $user = User::factory()->create();

        $first = Contact::factory()->create([
            'name' => 'Jane Lead',
            'email' => 'jane@example.test',
        ]);

        $second = Contact::factory()->create([
            'name' => 'Robert Client',
            'email' => 'robert@example.test',
        ]);

        Contact::factory()->create([
            'name' => 'Ignored Lead',
            'email' => 'ignored@example.test',
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson(route('crm.contacts.lookup', [
                'ids' => [$first->id, $second->id],
            ]));

        $response->assertOk();
        $response->assertJsonCount(2, 'contacts');
        $response->assertJsonFragment([
            'id' => $first->id,
            'label' => 'Jane Lead — jane@example.test',
        ]);
        $response->assertJsonFragment([
            'id' => $second->id,
            'label' => 'Robert Client — robert@example.test',
        ]);
        $response->assertJsonMissing([
            'email' => 'ignored@example.test',
        ]);
    }

    public function test_it_returns_empty_results_for_empty_search(): void
    {
        $user = User::factory()->create();

        Contact::factory()->create();

        $response = $this
            ->actingAs($user)
            ->getJson(route('crm.contacts.lookup'));

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
            ->getJson(route('crm.contacts.lookup', [
                'q' => 'Shared',
            ]));

        $response->assertOk();
        $response->assertJsonCount(20, 'contacts');
    }
}