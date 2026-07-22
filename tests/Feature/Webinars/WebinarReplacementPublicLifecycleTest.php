<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\CancelWebinarRegistrationAction;
use App\Modules\Webinars\Actions\ResolveWebinarJoinUrlAction;
use App\Modules\Webinars\Actions\ResolveWebinarRegistrationPublicStatusAction;
use App\Modules\Webinars\Actions\ResolveWebinarRegistrationReplacementChainAction;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Jobs\CancelWebinarRegistrationWithProviderJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Support\WebinarRegistrationCancelLinkGenerator;
use App\Modules\Webinars\Support\WebinarRegistrationThankYouLinkGenerator;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use LogicException;
use Tests\TestCase;

class WebinarReplacementPublicLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_thank_you_link_uses_the_canonical_registration_and_occurrence_across_multiple_replacements(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'replacement-status-chain',
        ]);
        $contact = Contact::factory()->create();
        $source = $this->occurrence($series, now()->addDay()->setTime(9, 0));
        $middle = $this->occurrence(
            $series,
            now()->addDays(3)->setTime(11, 0),
            $source,
        );
        $canonical = $this->occurrence(
            $series,
            now()->addDays(7)->setTime(15, 30),
            $middle,
        );
        $sourceRegistration = $this->registration(
            contact: $contact,
            webinar: $source,
            finalizationStatus: 'completed',
        );
        $middleRegistration = $this->registration(
            contact: $contact,
            webinar: $middle,
            finalizationStatus: 'completed',
            replacementOf: $sourceRegistration,
        );
        $canonicalRegistration = $this->registration(
            contact: $contact,
            webinar: $canonical,
            finalizationStatus: 'pending',
            replacementOf: $middleRegistration,
        );

        $url = app(WebinarRegistrationThankYouLinkGenerator::class)
            ->forRegistration($sourceRegistration);

        $this->get($url)
            ->assertOk()
            ->assertSee('data-registration-status="processing"', false)
            ->assertSee(
                $canonical->starts_at
                    ->timezone($canonical->timezone)
                    ->format('F j, Y'),
            )
            ->assertDontSee(
                $source->starts_at
                    ->timezone($source->timezone)
                    ->format('F j, Y'),
            );

        $canonicalRegistration->forceFill([
            'meta' => [
                WebinarRegistrationFinalizationResult::META_KEY => [
                    'status' => 'completed',
                    'mode' => 'replacement_reprovisioning',
                    'completed_at' => now()->toISOString(),
                ],
            ],
        ])->save();

        $this->get($url)
            ->assertOk()
            ->assertSee('data-registration-status="confirmed"', false);
    }

    public function test_old_cancellation_link_cancels_only_the_canonical_registration_and_queues_one_provider_cancellation(): void
    {
        Queue::fake();

        $series = WebinarSeries::factory()->create();
        $contact = Contact::factory()->create();
        $source = $this->occurrence($series, now()->addDay()->setTime(9, 0));
        $canonical = $this->occurrence(
            $series,
            now()->addDays(4)->setTime(14, 0),
            $source,
        );
        $sourceRegistration = $this->registration(
            contact: $contact,
            webinar: $source,
            finalizationStatus: 'completed',
        );
        $canonicalRegistration = $this->registration(
            contact: $contact,
            webinar: $canonical,
            finalizationStatus: 'completed',
            replacementOf: $sourceRegistration,
            meta: [
                'provider' => [
                    'name' => 'zoom',
                    'registrant_id' => 'replacement-registrant-123',
                ],
            ],
        );

        $showUrl = app(WebinarRegistrationCancelLinkGenerator::class)
            ->forRegistration($sourceRegistration);

        $this->get($showUrl)
            ->assertOk()
            ->assertSee(
                $canonical->starts_at
                    ->timezone($canonical->timezone)
                    ->format('F j, Y'),
            );

        $storeUrl = URL::temporarySignedRoute(
            name: 'webinar.registration.cancellation.store',
            expiration: now()->addMinutes(30),
            parameters: [
                'registration' => $sourceRegistration,
            ],
            absolute: false,
        );

        $this->post($storeUrl)
            ->assertOk()
            ->assertSee('Your registration has been cancelled');
        $this->post($storeUrl)->assertOk();

        $this->assertSame('registered', $sourceRegistration->refresh()->status);
        $this->assertNull($sourceRegistration->cancelled_at);
        $this->assertSame('cancelled', $canonicalRegistration->refresh()->status);
        $this->assertNotNull($canonicalRegistration->cancelled_at);
        $this->assertSame(
            $sourceRegistration->getKey(),
            data_get(
                $canonicalRegistration->meta,
                'cancellation.resolved_from_registration_id',
            ),
        );
        $this->assertSame(
            $canonicalRegistration->getKey(),
            data_get(
                $canonicalRegistration->meta,
                'cancellation.canonical_registration_id',
            ),
        );
        $this->assertSame(
            [
                $sourceRegistration->getKey(),
                $canonicalRegistration->getKey(),
            ],
            data_get(
                $canonicalRegistration->meta,
                'cancellation.traversed_registration_ids',
            ),
        );

        Queue::assertPushed(
            CancelWebinarRegistrationWithProviderJob::class,
            fn (CancelWebinarRegistrationWithProviderJob $job): bool =>
                $job->registrationId === $canonicalRegistration->getKey(),
        );
        Queue::assertPushed(CancelWebinarRegistrationWithProviderJob::class, 1);

        $this->assertSame(
            1,
            AutomationEventOutboxEvent::query()
                ->where('event_key', 'webinar.cancelled')
                ->where('subject_id', (string) $canonicalRegistration->getKey())
                ->count(),
        );
    }

    public function test_unresolved_occurrence_replacement_is_delayed_and_can_be_cancelled_from_the_old_link(): void
    {
        Queue::fake();

        $series = WebinarSeries::factory()->create();
        $source = $this->occurrence($series, now()->addDay());
        $this->occurrence($series, now()->addDays(2), $source);
        $sourceRegistration = $this->registration(
            contact: Contact::factory()->create(),
            webinar: $source,
            finalizationStatus: 'completed',
        );

        $this->assertSame(
            ResolveWebinarRegistrationPublicStatusAction::STATUS_DELAYED,
            app(ResolveWebinarRegistrationPublicStatusAction::class)
                ->handle($sourceRegistration),
        );

        $this->get(
            app(WebinarRegistrationThankYouLinkGenerator::class)
                ->forRegistration($sourceRegistration),
        )
            ->assertOk()
            ->assertSee('data-registration-status="delayed"', false);

        $cancelled = app(CancelWebinarRegistrationAction::class)->handle(
            $sourceRegistration,
            'replacement_status_link',
        );

        $this->assertTrue($cancelled->is($sourceRegistration));
        $this->assertSame('cancelled', $cancelled->status);
    }

    public function test_cancelled_canonical_registration_is_consistent_across_old_public_links(): void
    {
        $series = WebinarSeries::factory()->create();
        $contact = Contact::factory()->create();
        $source = $this->occurrence($series, now()->addDay());
        $canonical = $this->occurrence($series, now()->addDays(2), $source);
        $sourceRegistration = $this->registration(
            contact: $contact,
            webinar: $source,
            finalizationStatus: 'completed',
        );
        $canonicalRegistration = $this->registration(
            contact: $contact,
            webinar: $canonical,
            finalizationStatus: 'completed',
            replacementOf: $sourceRegistration,
        );
        $canonicalRegistration->forceFill([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ])->save();

        $this->get(
            app(WebinarRegistrationThankYouLinkGenerator::class)
                ->forRegistration($sourceRegistration),
        )
            ->assertOk()
            ->assertSee('data-registration-status="cancelled"', false);

        $this->get(
            app(WebinarRegistrationCancelLinkGenerator::class)
                ->forRegistration($sourceRegistration),
        )
            ->assertOk()
            ->assertSee('Your registration is already cancelled');

        $joinResolver = app(ResolveWebinarJoinUrlAction::class);

        $this->assertNull($joinResolver->handle($sourceRegistration));
        $this->assertFalse(
            $joinResolver->requiresReplacementRecovery($sourceRegistration),
        );
    }

    public function test_replacement_cycles_are_bounded_and_rejected_by_public_controllers(): void
    {
        $series = WebinarSeries::factory()->create();
        $contact = Contact::factory()->create();
        $firstWebinar = $this->occurrence($series, now()->addDay());
        $secondWebinar = $this->occurrence(
            $series,
            now()->addDays(2),
            $firstWebinar,
        );
        $firstWebinar->forceFill([
            'replacement_of_webinar_id' => $secondWebinar->getKey(),
        ])->save();
        $first = $this->registration(
            contact: $contact,
            webinar: $firstWebinar,
            finalizationStatus: 'completed',
        );
        $second = $this->registration(
            contact: $contact,
            webinar: $secondWebinar,
            finalizationStatus: 'completed',
            replacementOf: $first,
        );
        $first->forceFill([
            'replacement_of_registration_id' => $second->getKey(),
        ])->save();

        $chain = app(ResolveWebinarRegistrationReplacementChainAction::class)
            ->handle($first);

        $this->assertTrue($chain->cycleDetected);
        $this->assertSame(
            ResolveWebinarRegistrationPublicStatusAction::STATUS_DELAYED,
            app(ResolveWebinarRegistrationPublicStatusAction::class)
                ->handleChain($chain),
        );
        $this->assertNull(
            app(ResolveWebinarJoinUrlAction::class)->handle($first),
        );

        $this->get(
            app(WebinarRegistrationThankYouLinkGenerator::class)
                ->forRegistration($first),
        )->assertNotFound();
        $this->get(
            app(WebinarRegistrationCancelLinkGenerator::class)
                ->forRegistration($first),
        )->assertNotFound();
    }



    public function test_registration_replacement_must_follow_the_occurrence_replacement_chain(): void
    {
        $series = WebinarSeries::factory()->create();
        $contact = Contact::factory()->create();
        $source = $this->occurrence($series, now()->addDay());
        $unrelatedOccurrence = $this->occurrence($series, now()->addDays(2));
        $sourceRegistration = $this->registration(
            contact: $contact,
            webinar: $source,
            finalizationStatus: 'completed',
        );
        $this->registration(
            contact: $contact,
            webinar: $unrelatedOccurrence,
            finalizationStatus: 'completed',
            replacementOf: $sourceRegistration,
        );

        $chain = app(ResolveWebinarRegistrationReplacementChainAction::class)
            ->handle($sourceRegistration);

        $this->assertTrue($chain->occurrenceBoundaryViolated);
        $this->assertFalse($chain->safeForPublicLifecycle());
        $this->assertNull(
            app(ResolveWebinarJoinUrlAction::class)
                ->handle($sourceRegistration),
        );
    }

    public function test_cross_contact_replacement_links_are_rejected(): void
    {
        $series = WebinarSeries::factory()->create();
        $source = $this->occurrence($series, now()->addDay());
        $replacement = $this->occurrence(
            $series,
            now()->addDays(2),
            $source,
        );
        $sourceRegistration = $this->registration(
            contact: Contact::factory()->create(),
            webinar: $source,
            finalizationStatus: 'completed',
        );
        $this->registration(
            contact: Contact::factory()->create(),
            webinar: $replacement,
            finalizationStatus: 'completed',
            replacementOf: $sourceRegistration,
        );

        $chain = app(ResolveWebinarRegistrationReplacementChainAction::class)
            ->handle($sourceRegistration);

        $this->assertTrue($chain->contactBoundaryViolated);
        $this->assertFalse($chain->safeForPublicLifecycle());
        $this->assertNull(
            app(ResolveWebinarJoinUrlAction::class)
                ->handle($sourceRegistration),
        );

        $this->get(
            app(WebinarRegistrationThankYouLinkGenerator::class)
                ->forRegistration($sourceRegistration),
        )->assertNotFound();
        $this->get(
            app(WebinarRegistrationCancelLinkGenerator::class)
                ->forRegistration($sourceRegistration),
        )->assertNotFound();
    }

    public function test_cross_series_replacement_links_are_rejected(): void
    {
        $sourceSeries = WebinarSeries::factory()->create();
        $otherSeries = WebinarSeries::factory()->create();
        $contact = Contact::factory()->create();
        $source = $this->occurrence($sourceSeries, now()->addDay());
        $crossSeriesReplacement = $this->occurrence(
            $otherSeries,
            now()->addDays(2),
        );
        $sourceRegistration = $this->registration(
            contact: $contact,
            webinar: $source,
            finalizationStatus: 'completed',
        );
        $this->registration(
            contact: $contact,
            webinar: $crossSeriesReplacement,
            finalizationStatus: 'completed',
            replacementOf: $sourceRegistration,
        );

        $chain = app(ResolveWebinarRegistrationReplacementChainAction::class)
            ->handle($sourceRegistration);

        $this->assertTrue($chain->seriesBoundaryViolated);
        $this->assertNull(
            app(ResolveWebinarJoinUrlAction::class)
                ->handle($sourceRegistration),
        );

        $this->get(
            app(WebinarRegistrationThankYouLinkGenerator::class)
                ->forRegistration($sourceRegistration),
        )->assertNotFound();
        $this->get(
            app(WebinarRegistrationCancelLinkGenerator::class)
                ->forRegistration($sourceRegistration),
        )->assertNotFound();
        $this->post(URL::temporarySignedRoute(
            name: 'webinar.registration.cancellation.store',
            expiration: now()->addMinutes(30),
            parameters: [
                'registration' => $sourceRegistration,
            ],
            absolute: false,
        ))->assertNotFound();

        $this->expectException(LogicException::class);

        app(CancelWebinarRegistrationAction::class)->handle(
            $sourceRegistration,
        );
    }

    private function occurrence(
        WebinarSeries $series,
        mixed $startsAt,
        ?Webinar $replacementOf = null,
    ): Webinar {
        return Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'replacement_of_webinar_id' => $replacementOf?->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'timezone' => 'America/Chicago',
            'platform' => 'zoom',
            'external_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
        ]);
    }

    private function registration(
        Contact $contact,
        Webinar $webinar,
        string $finalizationStatus,
        ?WebinarRegistration $replacementOf = null,
        array $meta = [],
    ): WebinarRegistration {
        return WebinarRegistration::factory()
            ->for($contact)
            ->for($webinar)
            ->create([
                'replacement_of_registration_id' => $replacementOf?->getKey(),
                'webinar_slug' => $webinar->slug,
                'status' => 'registered',
                'cancelled_at' => null,
                'meta' => array_replace_recursive([
                    WebinarRegistrationFinalizationResult::META_KEY => [
                        'status' => $finalizationStatus,
                        'mode' => $replacementOf
                            ? 'replacement_reprovisioning'
                            : 'initial_registration',
                    ],
                ], $meta),
            ]);
    }
}