<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Core\Services\Contacts\ContactIndexFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_and_registry_criteria_combine_on_the_contacts_index(): void
    {
        $user = User::factory()->create();

        $matching = Contact::factory()->create([
            'first_name' => 'Taylor',
            'last_name' => 'Smith',
            'name' => null,
            'email' => 'taylor@example.test',
            'source' => 'Referral',
        ]);
        ContactTag::query()->create([
            'contact_id' => $matching->getKey(),
            'tag' => 'priority',
        ]);

        $wrongSearch = Contact::factory()->create([
            'name' => 'Jordan Jones',
            'email' => 'jordan@example.test',
            'source' => 'Referral',
        ]);
        ContactTag::query()->create([
            'contact_id' => $wrongSearch->getKey(),
            'tag' => 'priority',
        ]);

        $wrongSource = Contact::factory()->create([
            'name' => 'Taylor Smith',
            'email' => 'other@example.test',
            'source' => 'Website',
        ]);
        ContactTag::query()->create([
            'contact_id' => $wrongSource->getKey(),
            'tag' => 'priority',
        ]);

        $wrongTag = Contact::factory()->create([
            'name' => 'Taylor Smith',
            'email' => 'tag@example.test',
            'source' => 'Referral',
        ]);
        ContactTag::query()->create([
            'contact_id' => $wrongTag->getKey(),
            'tag' => 'other',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.index', [
                'search' => 'Taylor Smith',
                'source' => 'Referral',
                'tag' => 'priority',
            ]));

        $response->assertOk();

        $contacts = $response->viewData('contacts');
        $filters = $response->viewData('contactFilters');

        $this->assertEquals([$matching->getKey()], $contacts->pluck('id')->all());
        $this->assertSame('Taylor Smith', $filters['search']);
        $this->assertSame(['Referral'], $filters['criteria']['source']);
        $this->assertSame(['priority'], $filters['criteria']['tag']);
        $this->assertTrue($filters['has_filters']);
        $this->assertSame(1, $filters['secondary_active_count']);
        $this->assertSame(4, $response->viewData('totalContacts'));
        $this->assertContains('source', array_column($filters['primary'], 'key'));
        $this->assertContains('tag', array_column($filters['secondary'], 'key'));
    }

    public function test_search_matches_email_phone_and_split_first_last_name(): void
    {
        $name = Contact::factory()->create([
            'first_name' => 'Avery',
            'last_name' => 'Morgan',
            'name' => null,
            'email' => 'name@example.test',
            'phone' => '15550000001',
        ]);
        $email = Contact::factory()->create([
            'name' => 'Email Match',
            'email' => 'special-address@example.test',
            'phone' => '15550000002',
        ]);
        $phone = Contact::factory()->create([
            'name' => 'Phone Match',
            'email' => 'phone@example.test',
            'phone' => '15551234567',
        ]);

        $filters = app(ContactIndexFilterService::class);

        $nameState = $filters->state(['search' => 'Avery Morgan']);
        $emailState = $filters->state(['search' => 'special-address']);
        $phoneState = $filters->state(['search' => '51234567']);

        $this->assertEquals([$name->getKey()], $filters->query($nameState)->pluck('id')->all());
        $this->assertEquals([$email->getKey()], $filters->query($emailState)->pluck('id')->all());
        $this->assertEquals([$phone->getKey()], $filters->query($phoneState)->pluck('id')->all());
    }

    public function test_unknown_or_stale_index_filter_values_do_not_broaden_into_an_unintended_criterion(): void
    {
        Contact::factory()->count(2)->create([
            'source' => 'Referral',
        ]);

        $filters = app(ContactIndexFilterService::class);
        $state = $filters->state([
            'source' => 'Not a real source',
            'unknown_filter' => 'anything',
        ]);

        $this->assertSame([], $state['criteria']);
        $this->assertFalse($state['has_filters']);
        $this->assertSame(2, $filters->query($state)->count());
    }

    public function test_contacts_index_pagination_preserves_search_and_filter_query(): void
    {
        $user = User::factory()->create();

        Contact::factory()->count(25)->create([
            'name' => 'Referral Contact',
            'source' => 'Referral',
        ]);
        Contact::factory()->create([
            'name' => 'Other Contact',
            'source' => 'Website',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.index', [
                'search' => 'Referral',
                'source' => 'Referral',
            ]));

        $response->assertOk();

        $contacts = $response->viewData('contacts');
        $nextPageUrl = (string) $contacts->nextPageUrl();

        $this->assertSame(25, $contacts->total());
        $this->assertStringContainsString('search=Referral', $nextPageUrl);
        $this->assertStringContainsString('source=Referral', $nextPageUrl);
    }
}