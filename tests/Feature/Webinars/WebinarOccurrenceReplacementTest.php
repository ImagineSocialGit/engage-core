<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Actions\FinalizeWebinarRegistrationAction;
use App\Modules\Webinars\Actions\ReplaceWebinarOccurrenceAction;
use App\Modules\Webinars\Actions\ResolveWebinarJoinUrlAction;
use App\Modules\Webinars\Actions\SyncWebinarRegistrationToProviderAction;
use App\Modules\Webinars\Controllers\Public\WebinarJoinRedirectController;
use App\Modules\Webinars\Data\WebinarProviderSyncResult;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Jobs\SyncWebinarRegistrationToProviderJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Support\WebinarRegistrationThankYouLinkGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WebinarOccurrenceReplacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_replacement_preserves_history_reprovisions_active_registrants_and_is_idempotent(): void
    {
        Queue::fake();

        [$source, $replacement] = $this->occurrences();
        $first = $this->sourceRegistration($source, [
            'accepted_channels' => [
                'transactional' => ['email', 'sms'],
                'marketing' => ['email'],
            ],
        ]);
        $second = $this->sourceRegistration($source, [
            'accepted_channels' => [
                'transactional' => ['email'],
                'marketing' => [],
            ],
        ]);
        $cancelled = $this->sourceRegistration($source, [], [
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $pendingMessage = ScheduledMessage::factory()
            ->forRecipient($first->contact)
            ->create([
                'context_type' => $first->getMorphClass(),
                'context_id' => $first->getKey(),
                'status' => ScheduledMessage::STATUS_PENDING,
                'meta' => ['skip_when_join_clicked' => true],
            ]);

        MessageConsent::query()->create([
            'contact_id' => $first->contact_id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'consented_at' => now(),
            'source' => 'webinar_registration',
            'meta' => [],
        ]);
        $consentCount = MessageConsent::query()->count();

        $result = app(ReplaceWebinarOccurrenceAction::class)->handle(
            source: $source,
            replacement: $replacement,
        );

        $replacement->refresh();
        $firstReplacement = WebinarRegistration::query()
            ->where('webinar_id', $replacement->getKey())
            ->where('contact_id', $first->contact_id)
            ->firstOrFail();
        $secondReplacement = WebinarRegistration::query()
            ->where('webinar_id', $replacement->getKey())
            ->where('contact_id', $second->contact_id)
            ->firstOrFail();

        $this->assertSame($source->getKey(), $replacement->replacement_of_webinar_id);
        $this->assertSame(2, $result['eligible_registrations']);
        $this->assertSame(2, $result['created_registrations']);
        $this->assertSame(0, $result['adopted_registrations']);
        $this->assertSame(1, $result['skipped_source_messages']);
        $this->assertSame($first->getKey(), $firstReplacement->replacement_of_registration_id);
        $this->assertSame($second->getKey(), $secondReplacement->replacement_of_registration_id);
        $this->assertSame(
            ['email', 'sms'],
            data_get($firstReplacement->meta, 'accepted_channels.transactional'),
        );
        $this->assertSame(
            'replacement_reprovisioning',
            data_get($firstReplacement->meta, 'registration_finalization.mode'),
        );
        $this->assertSame(
            'queued',
            data_get($firstReplacement->meta, 'registration_finalization.status'),
        );
        $this->assertSame($consentCount, MessageConsent::query()->count());
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $pendingMessage->refresh()->status,
        );
        $this->assertSame(
            'occurrence_replaced',
            data_get(
                $first->refresh()->meta,
                'registration_finalization.completion_reason',
            ),
        );
        $this->assertDatabaseHas('webinar_registrations', [
            'id' => $cancelled->getKey(),
            'webinar_id' => $source->getKey(),
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseMissing('webinar_registrations', [
            'webinar_id' => $replacement->getKey(),
            'contact_id' => $cancelled->contact_id,
        ]);

        Queue::assertPushed(SyncWebinarRegistrationToProviderJob::class, 2);

        $secondResult = app(ReplaceWebinarOccurrenceAction::class)->handle(
            source: $source->refresh(),
            replacement: $replacement->refresh(),
        );

        $this->assertSame(0, $secondResult['created_registrations']);
        $this->assertSame(2, $secondResult['adopted_registrations']);
        $this->assertSame(
            2,
            WebinarRegistration::query()
                ->where('webinar_id', $replacement->getKey())
                ->count(),
        );
        Queue::assertPushed(SyncWebinarRegistrationToProviderJob::class, 2);
    }

    public function test_existing_target_registration_is_adopted_without_duplicate_provider_or_consent_state(): void
    {
        Queue::fake();

        [$source, $replacement] = $this->occurrences();
        $sourceRegistration = $this->sourceRegistration($source, [
            'accepted_channels' => [
                'transactional' => ['email'],
                'marketing' => ['email'],
            ],
        ]);
        $existingTarget = WebinarRegistration::factory()
            ->for($sourceRegistration->contact)
            ->for($replacement)
            ->create([
                'status' => 'registered',
                'source' => 'webinar_subdomain',
                'meta' => [
                    'provider' => [
                        'name' => 'zoom',
                        'registrant_id' => 'existing-registrant',
                        'join_url' => 'https://zoom.example.test/existing',
                    ],
                    'provider_sync' => [
                        'status' => 'succeeded',
                        'provider' => 'zoom',
                    ],
                    'registration_finalization' => [
                        'status' => 'completed',
                        'mode' => 'initial_registration',
                        'completed_at' => now()->subMinute()->toISOString(),
                    ],
                ],
            ]);

        $result = app(ReplaceWebinarOccurrenceAction::class)->handle(
            source: $source,
            replacement: $replacement,
        );

        $existingTarget->refresh();

        $this->assertSame(0, $result['created_registrations']);
        $this->assertSame(1, $result['adopted_registrations']);
        $this->assertSame(
            $sourceRegistration->getKey(),
            $existingTarget->replacement_of_registration_id,
        );
        $this->assertSame(
            'succeeded',
            data_get($existingTarget->meta, 'provider_sync.status'),
        );
        $this->assertSame(
            'replacement_reprovisioning',
            data_get($existingTarget->meta, 'registration_finalization.mode'),
        );
        $this->assertNotNull(
            data_get($existingTarget->meta, 'registration_finalization.initial_completed_at'),
        );
        $this->assertSame(
            1,
            WebinarRegistration::query()
                ->where('webinar_id', $replacement->getKey())
                ->where('contact_id', $sourceRegistration->contact_id)
                ->count(),
        );
        Queue::assertPushed(SyncWebinarRegistrationToProviderJob::class, 1);
    }

    public function test_replacement_finalization_registers_with_provider_then_plans_reminders_only(): void
    {
        [$sourceRegistration, $replacementRegistration] = $this->replacementRegistrationPair();

        $sync = Mockery::mock(SyncWebinarRegistrationToProviderAction::class);
        $sync->shouldReceive('handle')
            ->once()
            ->withArgs(fn (WebinarRegistration $registration): bool =>
                $registration->is($replacementRegistration))
            ->andReturn(new WebinarProviderSyncResult(
                status: WebinarProviderSyncResult::STATUS_SUCCEEDED,
                provider: 'zoom',
            ));

        $messages = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $messages->shouldReceive('handle')
            ->once()
            ->withArgs(fn (
                WebinarRegistration $registration,
                ?array $contextKeys,
                array $consentGrants,
            ): bool => $registration->is($replacementRegistration)
                && $contextKeys === ['reminders']
                && $consentGrants === [])
            ->andReturn([]);

        $result = (new FinalizeWebinarRegistrationAction(
            syncToProvider: $sync,
            dispatchRegistrationMessages: $messages,
        ))->handle($replacementRegistration);

        $this->assertSame(
            WebinarRegistrationFinalizationResult::STATUS_COMPLETED,
            $result?->status,
        );
        $this->assertSame(
            'replacement_reminders_planned',
            data_get(
                $replacementRegistration->refresh()->meta,
                'registration_finalization.completion_reason',
            ),
        );
        $this->assertSame(
            $sourceRegistration->getKey(),
            $replacementRegistration->replacement_of_registration_id,
        );
    }

    public function test_replacement_registrations_complete_or_retry_independently(): void
    {
        [, $firstReplacement] = $this->replacementRegistrationPair();
        [, $secondReplacement] = $this->replacementRegistrationPair();

        $sync = Mockery::mock(SyncWebinarRegistrationToProviderAction::class);
        $sync->shouldReceive('handle')
            ->once()
            ->withArgs(fn (WebinarRegistration $registration): bool =>
                $registration->is($firstReplacement))
            ->ordered()
            ->andReturn(new WebinarProviderSyncResult(
                status: WebinarProviderSyncResult::STATUS_SUCCEEDED,
                provider: 'zoom',
            ));
        $sync->shouldReceive('handle')
            ->once()
            ->withArgs(fn (WebinarRegistration $registration): bool =>
                $registration->is($secondReplacement))
            ->ordered()
            ->andReturn(new WebinarProviderSyncResult(
                status: WebinarProviderSyncResult::STATUS_RETRYABLE_FAILURE,
                provider: 'zoom',
                reason: 'provider_temporarily_unavailable',
            ));

        $messages = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $messages->shouldReceive('handle')
            ->once()
            ->withArgs(fn (WebinarRegistration $registration): bool =>
                $registration->is($firstReplacement))
            ->andReturn([]);

        $action = new FinalizeWebinarRegistrationAction(
            syncToProvider: $sync,
            dispatchRegistrationMessages: $messages,
        );

        $firstResult = $action->handle($firstReplacement);
        $secondResult = $action->handle($secondReplacement);

        $this->assertSame(
            WebinarRegistrationFinalizationResult::STATUS_COMPLETED,
            $firstResult?->status,
        );
        $this->assertSame(
            WebinarRegistrationFinalizationResult::STATUS_PENDING,
            $secondResult?->status,
        );
        $this->assertSame(
            'completed',
            data_get($firstReplacement->refresh()->meta, 'registration_finalization.status'),
        );
        $this->assertSame(
            'pending',
            data_get($secondReplacement->refresh()->meta, 'registration_finalization.status'),
        );
    }

    public function test_old_join_token_resolves_to_canonical_replacement_and_suppresses_its_live_reminders(): void
    {
        [$sourceRegistration, $replacementRegistration] = $this->replacementRegistrationPair(
            finalizationStatus: 'completed',
            replacementMeta: [
                'provider' => [
                    'name' => 'zoom',
                    'join_url' => 'https://zoom.example.test/replacement-room',
                ],
            ],
        );

        $replacementMessage = ScheduledMessage::factory()
            ->forRecipient($replacementRegistration->contact)
            ->create([
                'context_type' => $replacementRegistration->getMorphClass(),
                'context_id' => $replacementRegistration->getKey(),
                'status' => ScheduledMessage::STATUS_PENDING,
                'meta' => ['skip_when_join_clicked' => true],
            ]);
        $sourceMessage = ScheduledMessage::factory()
            ->forRecipient($sourceRegistration->contact)
            ->create([
                'context_type' => $sourceRegistration->getMorphClass(),
                'context_id' => $sourceRegistration->getKey(),
                'status' => ScheduledMessage::STATUS_PENDING,
                'meta' => ['skip_when_join_clicked' => true],
            ]);

        $resolver = app(ResolveWebinarJoinUrlAction::class);

        $this->assertSame(
            'https://zoom.example.test/replacement-room',
            $resolver->handle($sourceRegistration),
        );
        $this->assertSame(
            'https://zoom.example.test/replacement-room',
            $resolver->execute($sourceRegistration),
        );
        $this->assertTrue(
            $resolver->canonicalRegistration($sourceRegistration)
                ->is($replacementRegistration),
        );
        $this->assertSame(
            1,
            data_get($replacementRegistration->refresh()->meta, 'join_click_count'),
        );
        $this->assertSame(
            $sourceRegistration->getKey(),
            data_get(
                $replacementRegistration->meta,
                'join_interaction.resolved_from_registration_id',
            ),
        );
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $replacementMessage->refresh()->status,
        );
        $this->assertSame(
            ScheduledMessage::STATUS_PENDING,
            $sourceMessage->refresh()->status,
        );
    }

    public function test_unresolved_replacement_join_redirects_to_canonical_registration_status(): void
    {
        [$sourceRegistration, $replacementRegistration] = $this->replacementRegistrationPair(
            finalizationStatus: 'pending',
        );

        $resolver = app(ResolveWebinarJoinUrlAction::class);

        $this->assertNull($resolver->handle($sourceRegistration));
        $this->assertTrue($resolver->requiresReplacementRecovery($sourceRegistration));

        $thankYouLinks = Mockery::mock(WebinarRegistrationThankYouLinkGenerator::class);
        $thankYouLinks->shouldReceive('forRegistration')
            ->once()
            ->withArgs(fn (WebinarRegistration $registration): bool =>
                $registration->is($replacementRegistration))
            ->andReturn('https://webinar.example.test/replacement-status');

        $response = app(WebinarJoinRedirectController::class)->show(
            token: $sourceRegistration->join_token,
            resolveWebinarJoinUrlAction: $resolver,
            thankYouLinks: $thankYouLinks,
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'https://webinar.example.test/replacement-status',
            $response->headers->get('Location'),
        );
    }

    /** @return array{0: Webinar, 1: Webinar} */
    private function occurrences(): array
    {
        $series = WebinarSeries::factory()->create();
        $source = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => 'webinar',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $replacement = Webinar::factory()->meeting()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'join_url' => 'https://zoom.example.test/meeting-fallback',
        ]);

        return [$source, $replacement];
    }

    private function sourceRegistration(
        Webinar $webinar,
        array $meta = [],
        array $attributes = [],
    ): WebinarRegistration {
        return WebinarRegistration::factory()
            ->for(Contact::factory())
            ->for($webinar)
            ->create(array_replace([
                'status' => 'registered',
                'cancelled_at' => null,
                'meta' => $meta,
            ], $attributes));
    }

    /** @return array{0: WebinarRegistration, 1: WebinarRegistration} */
    private function replacementRegistrationPair(
        string $finalizationStatus = 'pending',
        array $replacementMeta = [],
    ): array {
        [$source, $replacement] = $this->occurrences();
        $sourceRegistration = $this->sourceRegistration($source, [
            'accepted_channels' => [
                'transactional' => ['email'],
                'marketing' => [],
            ],
        ]);
        $replacement->forceFill([
            'replacement_of_webinar_id' => $source->getKey(),
        ])->save();
        $replacementRegistration = WebinarRegistration::factory()
            ->for($sourceRegistration->contact)
            ->for($replacement)
            ->create([
                'replacement_of_registration_id' => $sourceRegistration->getKey(),
                'status' => 'pending',
                'source' => 'occurrence_replacement',
                'meta' => array_replace_recursive([
                    'accepted_channels' => [
                        'transactional' => ['email'],
                        'marketing' => [],
                    ],
                    'registration_finalization' => [
                        'status' => $finalizationStatus,
                        'mode' => 'replacement_reprovisioning',
                        'consent_transitions' => [],
                        'attempts' => 0,
                        'queue_dispatch_attempts' => 0,
                        'staged_at' => now()->toISOString(),
                        'last_state_changed_at' => now()->toISOString(),
                        'failure_reason' => null,
                    ],
                ], $replacementMeta),
            ]);

        return [$sourceRegistration, $replacementRegistration];
    }
}