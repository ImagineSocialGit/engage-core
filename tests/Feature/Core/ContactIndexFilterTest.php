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


}