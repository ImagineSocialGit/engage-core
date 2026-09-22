<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Actions\Contacts\NormalizeContactsAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Services\Contacts\ContactDuplicateInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_normalization_repairs_uniform_name_casing_and_phone_format_without_rewriting_intentional_mixed_case(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'STACEY',
            'last_name' => 'yarnall',
            'name' => 'STACEY YARNALL',
            'phone' => '(615) 555-1212',
        ]);
        $mixed = Contact::factory()->create([
            'first_name' => 'McKenna',
            'last_name' => "O'Neil",
            'name' => "McKenna O'Neil",
            'phone' => '+16155551213',
        ]);
        $invalid = Contact::factory()->create([
            'phone' => 'not-a-phone',
        ]);

        $preview = app(NormalizeContactsAction::class)->inspect();

        $this->assertSame(3, $preview['contacts_examined']);
        $this->assertSame(1, $preview['phone_numbers_changed']);
        $this->assertSame(1, $preview['invalid_phone_numbers']);

        $result = app(NormalizeContactsAction::class)->handle();

        $this->assertGreaterThanOrEqual(1, $result['contacts_changed']);
        $this->assertSame('Stacey', $contact->refresh()->first_name);
        $this->assertSame('Yarnall', $contact->last_name);
        $this->assertSame('Stacey Yarnall', $contact->name);
        $this->assertSame('+16155551212', $contact->phone);
        $this->assertSame('McKenna', $mixed->refresh()->first_name);
        $this->assertSame("O'Neil", $mixed->last_name);
        $this->assertSame('+16155551213', $mixed->phone);
        $this->assertSame('not-a-phone', $invalid->refresh()->phone);
    }

    public function test_duplicate_inspector_reports_same_name_candidates_and_canonical_phone_conflicts_without_merging_records(): void
    {
        $first = Contact::factory()->create([
            'first_name' => 'ANN',
            'last_name' => 'DUNN',
            'email' => 'ann.one@example.test',
            'phone' => '6155551212',
        ]);
        $second = Contact::factory()->create([
            'first_name' => 'Ann',
            'last_name' => 'Dunn',
            'email' => 'ann.two@example.test',
            'phone' => '+1 (615) 555-1213',
        ]);
        $third = Contact::factory()->create([
            'first_name' => 'Different',
            'last_name' => 'Person',
            'email' => 'different@example.test',
            'phone' => '+1 (615) 555-1212',
        ]);

        $result = app(ContactDuplicateInspector::class)->inspect();
        $nameGroup = collect($result['name_groups'])
            ->firstWhere('label', 'Ann Dunn');
        $phoneGroup = collect($result['phone_groups'])
            ->firstWhere('phone', '+16155551212');

        $this->assertNotNull($nameGroup);
        $this->assertTrue($nameGroup['different_emails']);
        $this->assertTrue($nameGroup['different_phones']);
        $this->assertEqualsCanonicalizing(
            [$first->getKey(), $second->getKey()],
            collect($nameGroup['contacts'])->pluck('id')->all(),
        );

        $this->assertNotNull($phoneGroup);
        $this->assertEqualsCanonicalizing(
            [$first->getKey(), $third->getKey()],
            collect($phoneGroup['contacts'])->pluck('id')->all(),
        );
        $this->assertDatabaseCount('contacts', 3);
    }
}