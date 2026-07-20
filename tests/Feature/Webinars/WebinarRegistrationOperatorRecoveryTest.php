<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\AddRegistrantToWebinarProviderAction;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Actions\FinalizeWebinarRegistrationAction;
use App\Modules\Webinars\Jobs\SyncWebinarRegistrationToProviderJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\Dashboard\WebinarActivityDashboardPanelProvider;
use App\Support\Dashboard\DashboardPanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WebinarRegistrationOperatorRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_retry_a_terminal_non_ambiguous_finalization_failure(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $registration = $this->registration(
            finalizationStatus: 'failed',
            providerSyncStatus: 'permanent_failure',
        );
        $returnTo = route('crm.webinar-series.index', ['attention' => 1]);

        $this->actingAs($user)
            ->from($returnTo)
            ->post(route(
                'crm.webinar-registrations.finalization.retry',
                $registration,
            ))
            ->assertRedirect($returnTo)
            ->assertSessionHas('success');

        $registration->refresh();

        $this->assertSame(
            'queued',
            data_get($registration->meta, 'registration_finalization.status'),
        );
        $this->assertSame(
            1,
            data_get($registration->meta, 'registration_finalization.operator_retry_count'),
        );
        $this->assertSame(
            $user->getKey(),
            data_get($registration->meta, 'registration_finalization.last_operator_retry_by'),
        );
        $this->assertSame(
            'pending',
            data_get($registration->meta, 'provider_sync.status'),
        );

        Queue::assertPushed(
            SyncWebinarRegistrationToProviderJob::class,
            fn (SyncWebinarRegistrationToProviderJob $job): bool =>
                $job->registrationId === $registration->getKey(),
        );
    }

    public function test_ordinary_retry_cannot_bypass_provider_reconciliation(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $registration = $this->registration(
            finalizationStatus: 'reconciliation_required',
            providerSyncStatus: 'reconciliation_required',
        );
        $returnTo = route('crm.webinar-series.index', ['attention' => 1]);

        $this->actingAs($user)
            ->from($returnTo)
            ->post(route(
                'crm.webinar-registrations.finalization.retry',
                $registration,
            ))
            ->assertRedirect($returnTo)
            ->assertSessionHas('error');

        $this->assertSame(
            'reconciliation_required',
            data_get($registration->fresh()?->meta, 'registration_finalization.status'),
        );

        Queue::assertNothingPushed();
    }

    public function test_operator_can_confirm_the_provider_registration_exists_and_complete_without_resubmission(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $registration = $this->registration(
            finalizationStatus: 'reconciliation_required',
            providerSyncStatus: 'reconciliation_required',
        );
        $returnTo = route('crm.webinar-series.index', ['attention' => 1]);

        $this->actingAs($user)
            ->from($returnTo)
            ->post(route(
                'crm.webinar-registrations.finalization.reconcile',
                $registration,
            ), [
                'decision' => 'provider_exists',
                'provider_registrant_id' => 'zoom-registrant-123',
                'provider_join_url' => 'https://zoom.example.test/join/123',
                'notes' => 'Verified in the Zoom registrant list.',
            ])
            ->assertRedirect($returnTo)
            ->assertSessionHas('success');

        $registration->refresh();

        $this->assertSame(
            'queued',
            data_get($registration->meta, 'registration_finalization.status'),
        );
        $this->assertSame(
            'succeeded',
            data_get($registration->meta, 'provider_sync.status'),
        );
        $this->assertSame(
            'zoom-registrant-123',
            data_get($registration->meta, 'provider.registrant_id'),
        );
        $this->assertSame(
            'https://zoom.example.test/join/123',
            data_get($registration->meta, 'provider.join_url'),
        );
        $this->assertSame(
            $user->getKey(),
            data_get($registration->meta, 'provider_sync.reconciliation_resolution.resolved_by'),
        );

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')->never();
        app()->instance(AddRegistrantToWebinarProviderAction::class, $provider);

        $messages = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $messages->shouldReceive('handle')->once()->andReturn([]);
        app()->instance(DispatchWebinarRegistrationMessagesAction::class, $messages);

        (new SyncWebinarRegistrationToProviderJob(
            (int) $registration->getKey(),
        ))->handle(app(FinalizeWebinarRegistrationAction::class));

        $registration->refresh();

        $this->assertSame(
            'completed',
            data_get($registration->meta, 'registration_finalization.status'),
        );
        $this->assertSame(
            'zoom-registrant-123',
            data_get($registration->meta, 'provider.registrant_id'),
        );
    }

    public function test_operator_can_confirm_provider_absence_and_authorize_one_safe_resubmission(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $registration = $this->registration(
            finalizationStatus: 'reconciliation_required',
            providerSyncStatus: 'reconciliation_required',
            additionalMeta: [
                'provider' => [
                    'name' => 'zoom',
                    'registrant_id' => 'stale-local-value',
                    'join_url' => 'https://zoom.example.test/stale',
                ],
            ],
        );
        $returnTo = route('crm.webinar-series.index', ['attention' => 1]);

        $this->actingAs($user)
            ->from($returnTo)
            ->post(route(
                'crm.webinar-registrations.finalization.reconcile',
                $registration,
            ), [
                'decision' => 'provider_absent',
                'notes' => 'No registrant matched the email address.',
            ])
            ->assertRedirect($returnTo)
            ->assertSessionHas('success');

        $registration->refresh();

        $this->assertSame(
            'queued',
            data_get($registration->meta, 'registration_finalization.status'),
        );
        $this->assertSame(
            'pending',
            data_get($registration->meta, 'provider_sync.status'),
        );
        $this->assertNull(data_get($registration->meta, 'provider'));
        $this->assertNotNull(
            data_get($registration->meta, 'provider_sync.resubmission_authorized_at'),
        );
        $this->assertSame(
            $user->getKey(),
            data_get($registration->meta, 'provider_sync.resubmission_authorized_by'),
        );

        Queue::assertPushed(SyncWebinarRegistrationToProviderJob::class, 1);
    }

    public function test_confirming_provider_existence_requires_remote_identity_and_join_url(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $registration = $this->registration(
            finalizationStatus: 'reconciliation_required',
            providerSyncStatus: 'reconciliation_required',
        );

        $this->actingAs($user)
            ->post(route(
                'crm.webinar-registrations.finalization.reconcile',
                $registration,
            ), [
                'decision' => 'provider_exists',
            ])
            ->assertSessionHasErrors([
                'provider_registrant_id',
                'provider_join_url',
            ]);

        $this->assertSame(
            'reconciliation_required',
            data_get($registration->fresh()?->meta, 'registration_finalization.status'),
        );

        Queue::assertNothingPushed();
    }

    public function test_reconciliation_cannot_be_reapplied_after_the_state_is_resolved(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $registration = $this->registration(
            finalizationStatus: 'completed',
            providerSyncStatus: 'succeeded',
        );
        $returnTo = route('crm.webinar-series.index');

        $this->actingAs($user)
            ->from($returnTo)
            ->post(route(
                'crm.webinar-registrations.finalization.reconcile',
                $registration,
            ), [
                'decision' => 'provider_absent',
            ])
            ->assertRedirect($returnTo)
            ->assertSessionHas('success');

        Queue::assertNothingPushed();
    }

    public function test_attention_view_includes_ended_webinars_and_only_shows_appropriate_recovery_controls(): void
    {
        $user = User::factory()->create();
        $webinar = Webinar::factory()->create([
            'title' => 'Ended Webinar Requiring Recovery',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);

        $failed = $this->registration(
            finalizationStatus: 'failed',
            providerSyncStatus: 'permanent_failure',
            webinar: $webinar,
        );
        $reconciliation = $this->registration(
            finalizationStatus: 'reconciliation_required',
            providerSyncStatus: 'reconciliation_required',
            webinar: $webinar,
        );
        $this->registration(
            finalizationStatus: 'completed',
            providerSyncStatus: 'succeeded',
            webinar: $webinar,
        );

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index'))
            ->assertOk()
            ->assertDontSee('Ended Webinar Requiring Recovery');

        $this->actingAs($user)
            ->get(route('crm.webinar-series.index', ['attention' => 1]))
            ->assertOk()
            ->assertSee($webinar->title)
            ->assertSee(route(
                'crm.webinar-registrations.finalization.retry',
                $failed,
            ), false)
            ->assertSee(route(
                'crm.webinar-registrations.finalization.reconcile',
                $reconciliation,
            ), false)
            ->assertSee('name="decision" value="provider_exists"', false)
            ->assertSee('name="decision" value="provider_absent"', false);
    }

    public function test_dashboard_panel_prioritizes_unresolved_finalization_work(): void
    {
        $user = User::factory()->create();
        $registration = $this->registration(
            finalizationStatus: 'reconciliation_required',
            providerSyncStatus: 'reconciliation_required',
        );
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn (): User => $user);

        $panel = app(WebinarActivityDashboardPanelProvider::class)
            ->panel($request);

        $this->assertSame(1, $panel['attention_count']);
        $this->assertSame('immediate_work', $panel['slot']);
        $this->assertIsString($panel['title']);
        $this->assertNotSame('', trim($panel['title']));
        $this->assertSame(
            'webinar_registration_finalization',
            $panel['items']->first()['type'],
        );
        $this->assertSame(
            route('crm.webinar-series.index', ['attention' => 1]),
            $panel['items']->first()['href'],
        );
        $this->assertSame(
            (string) $registration->getKey(),
            $panel['items']->first()['key'],
        );

        Config::set('modules.dashboard', [
            'slots' => [
                'immediate_work' => [
                    'max' => 2,
                    'hide_when_empty' => false,
                    'panels' => [],
                ],
                'context' => [
                    'max' => 2,
                    'hide_when_empty' => true,
                    'panels' => ['webinars.activity'],
                ],
            ],
            'presets' => [],
        ]);
        Config::set('client.preset', null);

        $panelsBySlot = app(DashboardPanelRegistry::class)
            ->panelsFor($request);

        $this->assertSame(
            'webinars.activity',
            $panelsBySlot->get('immediate_work')->first()['key'],
        );
        $this->assertTrue($panelsBySlot->get('context')->isEmpty());
    }

    /**
     * @param array<string, mixed> $additionalMeta
     */
    private function registration(
        string $finalizationStatus,
        string $providerSyncStatus,
        ?Webinar $webinar = null,
        array $additionalMeta = [],
    ): WebinarRegistration {
        $webinar ??= Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-webinar-123',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Example Registrant',
            'email' => fake()->unique()->safeEmail(),
        ]);
        $reason = $finalizationStatus === 'reconciliation_required'
            ? 'provider_submission_outcome_unknown'
            : ($finalizationStatus === 'failed' ? 'retry_exhausted' : null);

        return WebinarRegistration::factory()
            ->for($webinar)
            ->for($contact)
            ->create([
                'status' => 'registered',
                'meta' => array_replace_recursive([
                    'registration_finalization' => [
                        'status' => $finalizationStatus,
                        'mode' => 'initial_registration',
                        'consent_transitions' => [],
                        'attempts' => 5,
                        'queue_dispatch_attempts' => 1,
                        'failure_reason' => $reason,
                        'failed_at' => $reason ? now()->toISOString() : null,
                        'reconciliation_required_at' => $finalizationStatus === 'reconciliation_required'
                            ? now()->toISOString()
                            : null,
                    ],
                    'provider_sync' => [
                        'status' => $providerSyncStatus,
                        'provider' => 'zoom',
                        'attempts' => 1,
                        'failure_reason' => $providerSyncStatus === 'reconciliation_required'
                            ? 'provider_submission_outcome_unknown'
                            : ($providerSyncStatus === 'permanent_failure'
                                ? 'provider_rejected_registration'
                                : null),
                        'reconciliation_required_at' => $providerSyncStatus === 'reconciliation_required'
                            ? now()->toISOString()
                            : null,
                    ],
                ], $additionalMeta),
            ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}