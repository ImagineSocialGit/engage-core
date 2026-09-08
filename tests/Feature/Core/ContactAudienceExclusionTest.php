<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactAudienceExclusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reusable_contact_filter_can_subtract_criteria_and_manual_contact_ids(): void
    {
        $kept = Contact::factory()->create(['source' => 'Webinar']);
        $excludedByTag = Contact::factory()->create(['source' => 'Webinar']);
        $excludedManually = Contact::factory()->create(['source' => 'Webinar']);
        $wrongSource = Contact::factory()->create(['source' => 'Referral']);

        ContactTag::query()->create([
            'contact_id' => $kept->getKey(),
            'tag' => 'missed_webinar',
        ]);
        ContactTag::query()->create([
            'contact_id' => $excludedByTag->getKey(),
            'tag' => 'missed_webinar',
        ]);
        ContactTag::query()->create([
            'contact_id' => $excludedByTag->getKey(),
            'tag' => 'do_not_nurture',
        ]);
        ContactTag::query()->create([
            'contact_id' => $excludedManually->getKey(),
            'tag' => 'missed_webinar',
        ]);
        ContactTag::query()->create([
            'contact_id' => $wrongSource->getKey(),
            'tag' => 'missed_webinar',
        ]);

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'criteria',
            'criteria' => [
                'source' => ['Webinar'],
                'tag' => ['missed_webinar'],
            ],
            'exclude_criteria' => [
                'tag' => ['do_not_nurture'],
            ],
            'exclude_contact_ids' => [
                $excludedManually->getKey(),
            ],
        ]);

        $this->assertSame(
            [$kept->getKey()],
            $resolved->pluck('id')->all(),
        );
    }

    public function test_unknown_exclusion_criterion_fails_closed_instead_of_broadening_a_bulk_audience(): void
    {
        Contact::factory()->count(2)->create();

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'all',
            'exclude_criteria' => [
                'unknown_criterion' => ['anything'],
            ],
        ]);

        $this->assertSame(0, $resolved->count());
    }
}