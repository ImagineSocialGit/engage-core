<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactFilterCriteriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_composite_criteria_use_and_between_categories_and_or_within_a_category(): void
    {
        $included = Contact::factory()->create([
            'source' => 'Database',
            'subsource' => 'FL VA Agents',
        ]);

        ContactTag::query()->create([
            'contact_id' => $included->id,
            'tag' => 'priority_agent',
        ]);

        $wrongTag = Contact::factory()->create([
            'source' => 'Database',
            'subsource' => 'FL VA Agents',
        ]);

        ContactTag::query()->create([
            'contact_id' => $wrongTag->id,
            'tag' => 'other',
        ]);

        $wrongSource = Contact::factory()->create([
            'source' => 'Other',
            'subsource' => 'FL VA Agents',
        ]);

        ContactTag::query()->create([
            'contact_id' => $wrongSource->id,
            'tag' => 'priority_agent',
        ]);

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'criteria',
            'criteria' => [
                'source' => ['Database'],
                'tag' => ['priority_agent'],
            ],
        ]);

        $this->assertEquals([$included->id], $resolved->pluck('id')->all());
    }

    public function test_empty_composite_criteria_do_not_fall_back_to_all_contacts(): void
    {
        Contact::factory()->count(2)->create();

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'criteria',
            'criteria' => [],
        ]);

        $this->assertSame(0, $resolved->count());
    }
    public function test_unknown_or_empty_runtime_criteria_fail_closed_instead_of_broadening_to_all_contacts(): void
    {
        Contact::factory()->count(2)->create();

        $unknown = app(ContactFilterResolver::class)->resolve([
            'type' => 'criteria',
            'criteria' => [
                'unknown_criterion' => ['anything'],
            ],
        ]);

        $emptyKnown = app(ContactFilterResolver::class)->resolve([
            'type' => 'criteria',
            'criteria' => [
                'source' => ['source_that_does_not_exist'],
            ],
        ]);

        $this->assertSame(0, $unknown->count());
        $this->assertSame(0, $emptyKnown->count());
    }

}