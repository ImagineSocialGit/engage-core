<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Jobs\SyncWebinarRegistrationToProviderJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarOccurrenceReplacementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_operator_can_confirm_occurrence_replacement(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        [$source, $replacement] = $this->occurrences();
        $registration = $this->sourceRegistration($source);

        $response = $this->actingAs($user)->post(
            route('crm.webinars.replacements.store', $source),
            [
                'replacement_webinar_id' => $replacement->getKey(),
                'confirm_replacement' => '1',
            ],
        );

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHas(
            'success',
            'Occurrence replacement prepared. Replacement registrations will finalize independently.',
        );
        $response->assertSessionHas('occurrence_replacement_result', function (array $result): bool {
            return $result['eligible_registrations'] === 1
                && $result['created_registrations'] === 1
                && $result['adopted_registrations'] === 0
                && ($result['queue_status_counts']['in_progress'] ?? 0) === 1;
        });

        $this->assertSame(
            $source->getKey(),
            $replacement->refresh()->replacement_of_webinar_id,
        );
        $this->assertDatabaseHas('webinar_registrations', [
            'webinar_id' => $replacement->getKey(),
            'contact_id' => $registration->contact_id,
            'replacement_of_registration_id' => $registration->getKey(),
            'source' => 'occurrence_replacement',
        ]);
        Queue::assertPushed(SyncWebinarRegistrationToProviderJob::class, 1);
    }

    public function test_replacement_requires_affirmative_confirmation(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        [$source, $replacement] = $this->occurrences();
        $this->sourceRegistration($source);

        $response = $this->actingAs($user)
            ->from(route('crm.webinar-series.index'))
            ->post(route('crm.webinars.replacements.store', $source), [
                'replacement_webinar_id' => $replacement->getKey(),
            ]);

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHasErrors('confirm_replacement');
        $this->assertNull($replacement->refresh()->replacement_of_webinar_id);
        $this->assertDatabaseMissing('webinar_registrations', [
            'webinar_id' => $replacement->getKey(),
            'source' => 'occurrence_replacement',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_cross_series_replacement_is_rejected_by_domain_action(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        [$source] = $this->occurrences();
        $otherSeriesReplacement = Webinar::factory()->meeting()->create([
            'webinar_series_id' => WebinarSeries::factory()->meeting(),
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);

        $response = $this->actingAs($user)->post(
            route('crm.webinars.replacements.store', $source),
            [
                'replacement_webinar_id' => $otherSeriesReplacement->getKey(),
                'confirm_replacement' => '1',
            ],
        );

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHas(
            'error',
            'A Webinar occurrence replacement must remain within the same Webinar series.',
        );
        $this->assertNull(
            $otherSeriesReplacement->refresh()->replacement_of_webinar_id,
        );
        Queue::assertNothingPushed();
    }

    public function test_replacement_resubmission_is_idempotent_and_reuses_target_registration(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        [$source, $replacement] = $this->occurrences();
        $registration = $this->sourceRegistration($source);
        $payload = [
            'replacement_webinar_id' => $replacement->getKey(),
            'confirm_replacement' => '1',
        ];

        $this->actingAs($user)->post(
            route('crm.webinars.replacements.store', $source),
            $payload,
        )->assertRedirect(route('crm.webinar-series.index'));

        $second = $this->actingAs($user)->post(
            route('crm.webinars.replacements.store', $source),
            $payload,
        );

        $second->assertRedirect(route('crm.webinar-series.index'));
        $second->assertSessionHas('occurrence_replacement_result', function (array $result): bool {
            return $result['created_registrations'] === 0
                && $result['adopted_registrations'] === 1;
        });

        $this->assertSame(
            1,
            WebinarRegistration::query()
                ->where('webinar_id', $replacement->getKey())
                ->where('contact_id', $registration->contact_id)
                ->count(),
        );
        Queue::assertPushed(SyncWebinarRegistrationToProviderJob::class, 1);
    }

    public function test_index_displays_event_types_replacement_provenance_and_reprovisioning_attention(): void
    {
        $user = User::factory()->create();
        [$source, $replacement] = $this->occurrences();
        $sourceRegistration = $this->sourceRegistration($source);

        $replacement->forceFill([
            'replacement_of_webinar_id' => $source->getKey(),
        ])->save();

        WebinarRegistration::factory()
            ->for($sourceRegistration->contact)
            ->for($replacement)
            ->create([
                'replacement_of_registration_id' => $sourceRegistration->getKey(),
                'source' => 'occurrence_replacement',
                'meta' => [
                    'registration_finalization' => [
                        'status' => 'failed',
                        'mode' => 'replacement_reprovisioning',
                        'failure_reason' => 'provider_temporarily_unavailable',
                        'attempts' => 1,
                    ],
                ],
            ]);

        $response = $this->actingAs($user)->get(
            route('crm.webinar-series.index', ['attention' => 1]),
        );

        $response->assertOk();
        $response->assertSeeText('Zoom Meeting');
        $response->assertSeeText('Replaces #'.$source->getKey());
        $response->assertSeeText('1 replacement needs attention');
        $response->assertSeeText('Registration finalization needs attention');
    }

    /** @return array{0: Webinar, 1: Webinar} */
    private function occurrences(): array
    {
        $series = WebinarSeries::factory()->create([
            'title' => 'Weekly Planning Session',
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
        ]);
        $source = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
            'title' => 'Weekly Planning Session',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $replacement = Webinar::factory()->meeting()->create([
            'webinar_series_id' => $series->getKey(),
            'title' => 'Weekly Planning Session',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        return [$source, $replacement];
    }

    private function sourceRegistration(Webinar $source): WebinarRegistration
    {
        return WebinarRegistration::factory()
            ->for(Contact::factory())
            ->for($source)
            ->create([
                'status' => 'registered',
                'cancelled_at' => null,
                'meta' => [
                    'accepted_channels' => [
                        'transactional' => ['email'],
                        'marketing' => [],
                    ],
                ],
            ]);
    }
}