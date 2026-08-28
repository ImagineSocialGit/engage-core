<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Campaigns\Services\CampaignAnnualTouchAudienceService;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use App\Modules\Core\Support\Contacts\ContactFilterCriterionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignAnnualTouchAudienceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_and_specific_contact_audiences_do_not_require_status(): void
    {
        $first = Contact::query()->create([
            'first_name' => 'First',
            'email' => 'first@example.test',
        ]);
        $second = Contact::query()->create([
            'first_name' => 'Second',
            'email' => 'second@example.test',
        ]);

        $service = app(CampaignAnnualTouchAudienceService::class);

        $all = $service->normalize(['mode' => 'all']);
        $specific = $service->normalize([
            'mode' => 'contacts',
            'contact_ids' => [$second->getKey()],
        ]);

        $this->assertSame('all', $all['mode']);
        $this->assertSame(2, $service->matchingCountForFilter($all));
        $this->assertSame('contacts', $specific['mode']);
        $this->assertEquals([(int) $second->getKey()], $specific['contact_ids']);
        $this->assertEquals(
            [(int) $second->getKey()],
            $service->queryForFilter($specific)->pluck('contacts.id')->all(),
        );
        $this->assertFalse(
            $service->queryForFilter($specific)->whereKey($first->getKey())->exists(),
        );
    }

    public function test_unavailable_saved_criterion_is_preserved_and_fails_closed(): void
    {
        Contact::query()->create([
            'first_name' => 'Would Otherwise Match',
            'email' => 'match@example.test',
        ]);

        $program = CampaignTouchProgram::query()->create([
            'key' => 'optional_criterion_program',
            'name' => 'Optional criterion program',
            'audience_type' => CampaignTouchProgram::AUDIENCE_FILTER,
            'audience_key' => null,
            'audience_filter' => [
                'mode' => 'criteria',
                'criteria' => [
                    'relationship' => ['fan'],
                ],
                'contact_ids' => [],
                'exclude' => [
                    'criteria' => [],
                    'contact_ids' => [],
                ],
            ],
            'recurrence' => CampaignTouchProgram::RECURRENCE_ANNUAL,
            'repeat_years' => 10,
            'is_active' => true,
        ]);

        $registry = new ContactFilterCriterionRegistry([]);
        $service = new CampaignAnnualTouchAudienceService(
            criteria: $registry,
            resolver: new ContactFilterResolver($registry),
        );

        $normalized = $service->normalize(
            input: [
                'mode' => 'criteria',
                'criteria' => [],
            ],
            program: $program,
        );

        $this->assertEquals(
            ['relationship' => ['fan']],
            $normalized['criteria'],
        );
        $this->assertSame(0, $service->matchingCountForFilter($normalized));
    }

    public function test_any_matching_exclusion_group_removes_the_contact(): void
    {
        $included = Contact::query()->create([
            'first_name' => 'Included',
            'email' => 'included@example.test',
            'source' => 'organic',
        ]);
        $sourceExcluded = Contact::query()->create([
            'first_name' => 'Source Excluded',
            'email' => 'source-excluded@example.test',
            'source' => 'referral',
        ]);
        $tagExcluded = Contact::query()->create([
            'first_name' => 'Tag Excluded',
            'email' => 'tag-excluded@example.test',
            'source' => 'organic',
        ]);
        ContactTag::query()->create([
            'contact_id' => $tagExcluded->getKey(),
            'tag' => 'VIP',
        ]);

        $service = app(CampaignAnnualTouchAudienceService::class);
        $filter = $service->normalize([
            'mode' => 'all',
            'exclude_criteria' => [
                'source' => ['referral'],
                'tag' => ['VIP'],
            ],
        ]);

        $this->assertEquals(
            [(int) $included->getKey()],
            $service->queryForFilter($filter)->pluck('contacts.id')->all(),
        );
        $this->assertFalse(
            $service->queryForFilter($filter)->whereKey($sourceExcluded->getKey())->exists(),
        );
    }
}