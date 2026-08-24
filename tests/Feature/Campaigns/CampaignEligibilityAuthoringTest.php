<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use App\Modules\Core\Services\Contacts\Filters\ImportBatchContactFilterCriterion;
use App\Modules\Core\Services\Contacts\Filters\SourceContactFilterCriterion;
use App\Modules\Core\Services\Contacts\Filters\TagContactFilterCriterion;
use App\Modules\Core\Support\Contacts\ContactFilterCriterionRegistry;
use App\Modules\Workflow\Services\Contacts\Filters\StatusContactFilterCriterion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignEligibilityAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->app->instance(
            ContactFilterCriterionRegistry::class,
            new ContactFilterCriterionRegistry([
                new StatusContactFilterCriterion(),
                new SourceContactFilterCriterion(),
                new TagContactFilterCriterion(),
                new ImportBatchContactFilterCriterion(),
            ]),
        );

        $this->app->forgetInstance(ContactFilterResolver::class);
    }

    public function test_edit_exposes_stable_status_keys_and_does_not_offer_import_batch_as_automatic_campaign_criteria(): void
    {
        $user = User::factory()->create();
        ContactStatus::query()->create([
            'key' => 'prospect_nurture',
            'name' => 'Prospect – Nurture',
            'description' => 'Fixture status for Campaign eligibility authoring.',
            'category' => 'test',
            'sort_order' => 10,
            'is_active' => true,
            'source_version' => 1,
        ]);
        ContactImportBatch::factory()->create();

        $campaign = Campaign::factory()->create([
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_MANUAL,
            'eligibility_filter' => [],
        ]);

        $response = $this->actingAs($user)
            ->get(route('crm.campaigns.edit', $campaign))
            ->assertOk()
            ->assertViewIs('crm.campaigns.edit');

        $eligibility = $response->viewData('eligibility');
        $status = collect($eligibility['criteria'])->firstWhere('key', 'status');

        $this->assertNotNull($status);
        $this->assertEquals(
            ['prospect_nurture'],
            array_column($status['options'], 'value'),
        );
        $this->assertFalse(in_array(
            'import_batch',
            array_column($eligibility['criteria'], 'key'),
            true,
        ));

        $workspace = $response->viewData('workspace');
        $start = collect($workspace['builder_stages'])->firstWhere('key', 'start');

        $this->assertTrue($start['editable']);
        $this->assertSame('configured', $start['state']);
    }

    public function test_preview_counts_contacts_matching_all_selected_criterion_types(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_MANUAL,
            'eligibility_filter' => [],
        ]);

        $eligible = Contact::withoutEvents(fn () => Contact::factory()->create([
            'source' => 'Database',
        ]));
        $wrongTag = Contact::withoutEvents(fn () => Contact::factory()->create([
            'source' => 'Database',
        ]));
        $wrongSource = Contact::withoutEvents(fn () => Contact::factory()->create([
            'source' => 'Website',
        ]));

        foreach ([$eligible, $wrongSource] as $contact) {
            ContactTag::withoutEvents(fn () => ContactTag::query()->create([
                'contact_id' => $contact->getKey(),
                'tag' => 'VIP',
            ]));
        }

        ContactTag::withoutEvents(fn () => ContactTag::query()->create([
            'contact_id' => $wrongTag->getKey(),
            'tag' => 'Other',
        ]));

        $this->actingAs($user)
            ->postJson(route('crm.campaigns.eligibility.preview', $campaign), [
                'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
                'reentry_policy' => Campaign::REENTRY_NEVER,
                'ineligible_behavior' => Campaign::INELIGIBLE_CANCEL,
                'eligibility_criteria' => [
                    'source' => ['Database'],
                    'tag' => ['VIP'],
                ],
            ])
            ->assertOk()
            ->assertJson([
                'matching_count' => 1,
            ]);
    }

    public function test_update_persists_eligibility_policy_and_marks_campaign_customized(): void
    {
        $user = User::factory()->create();

        $contact = Contact::withoutEvents(fn () => Contact::factory()->create([
            'source' => 'Database',
        ]));
        ContactTag::withoutEvents(fn () => ContactTag::query()->create([
            'contact_id' => $contact->getKey(),
            'tag' => 'VIP',
        ]));

        $campaign = Campaign::factory()->create([
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_MANUAL,
            'eligibility_filter' => [],
            'is_customized' => false,
            'customized_at' => null,
        ]);

        $this->actingAs($user)
            ->patch(route('crm.campaigns.eligibility.update', $campaign), [
                'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
                'reentry_policy' => Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN,
                'ineligible_behavior' => Campaign::INELIGIBLE_PAUSE,
                'eligibility_criteria' => [
                    'source' => ['Database'],
                    'tag' => ['VIP'],
                ],
            ])
            ->assertRedirect(route('crm.campaigns.edit', $campaign));

        $campaign->refresh();

        $this->assertEquals([
            'source' => ['Database'],
            'tag' => ['VIP'],
        ], $campaign->eligibility_filter);
        $this->assertSame(
            Campaign::ENROLLMENT_MODE_AUTOMATIC,
            $campaign->enrollment_mode,
        );
        $this->assertSame(
            Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN,
            $campaign->reentry_policy,
        );
        $this->assertSame(
            Campaign::INELIGIBLE_PAUSE,
            $campaign->ineligible_behavior,
        );
        $this->assertTrue($campaign->is_customized);
        $this->assertNotNull($campaign->customized_at);
    }

    public function test_automatic_enrollment_requires_at_least_one_eligibility_condition(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_MANUAL,
            'eligibility_filter' => [],
        ]);

        $this->actingAs($user)
            ->from(route('crm.campaigns.edit', $campaign))
            ->patch(route('crm.campaigns.eligibility.update', $campaign), [
                'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
                'reentry_policy' => Campaign::REENTRY_NEVER,
                'ineligible_behavior' => Campaign::INELIGIBLE_CANCEL,
                'eligibility_criteria' => [],
            ])
            ->assertRedirect(route('crm.campaigns.edit', $campaign))
            ->assertSessionHasErrors('eligibility_criteria');

        $this->assertSame(
            Campaign::ENROLLMENT_MODE_MANUAL,
            $campaign->refresh()->enrollment_mode,
        );
    }

    public function test_existing_unavailable_criterion_is_preserved_when_editing_available_start_policy(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_MANUAL,
            'eligibility_filter' => [
                'future_module_fact' => ['qualified'],
            ],
        ]);

        $this->actingAs($user)
            ->patch(route('crm.campaigns.eligibility.update', $campaign), [
                'enrollment_mode' => Campaign::ENROLLMENT_MODE_MANUAL,
                'reentry_policy' => Campaign::REENTRY_NEVER,
                'ineligible_behavior' => Campaign::INELIGIBLE_CONTINUE,
                'eligibility_criteria' => [],
            ])
            ->assertRedirect(route('crm.campaigns.edit', $campaign));

        $this->assertEquals([
            'future_module_fact' => ['qualified'],
        ], $campaign->refresh()->eligibility_filter);
    }
}