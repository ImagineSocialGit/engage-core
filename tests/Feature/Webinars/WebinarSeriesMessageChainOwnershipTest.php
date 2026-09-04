<?php

namespace Tests\Feature\Webinars;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
use App\Modules\Webinars\Actions\DeleteWebinarSeriesAction;
use App\Modules\Webinars\Actions\DuplicateWebinarSeriesMessageChainsAction;
use App\Modules\Webinars\Actions\StartWebinarMessageChainEnrollmentAction;
use App\Modules\Webinars\Actions\UpdateWebinarSeriesMessageTemplateAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileChainBinding;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesMessageChainBinding;
use App\Modules\Webinars\Validation\WebinarMessageChainSetupValidationContributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarSeriesMessageChainOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('modules.enabled', ['webinars', 'messaging']);
        Config::set(
            'webinars.message_areas',
            require base_path('config/webinars/message_areas.php'),
        );
    }

    public function test_series_duplication_reuses_immutable_templates_until_copy_on_write_and_runtime_uses_the_series_chain(): void
    {
        [$profile, $profileChain] = $this->registrationProfileAndChain();
        $series = WebinarSeries::factory()->create([
            'title' => 'Series One',
            'slug' => 'series-one',
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);

        $bindings = app(DuplicateWebinarSeriesMessageChainsAction::class)->handle(
            targetSeries: $series,
        );

        $this->assertCount(2, $bindings);
        $this->assertSame(1, $bindings->pluck('message_chain_id')->unique()->count());

        $seriesChain = $bindings->firstOrFail()->messageChain;
        $this->assertNotSame($profileChain->getKey(), $seriesChain->getKey());
        $this->assertEquals(
            $profileChain->requireCurrentVersion()
                ->steps
                ->flatMap(fn (MessageChainStep $step) => $step->variants)
                ->pluck('message_template_version_id')
                ->sort()
                ->values()
                ->all(),
            $seriesChain->requireCurrentVersion()
                ->steps
                ->flatMap(fn (MessageChainStep $step) => $step->variants)
                ->pluck('message_template_version_id')
                ->sort()
                ->values()
                ->all(),
        );

        $contact = Contact::factory()->create([
            'email' => 'series-owner@example.test',
        ]);
        $webinar = Webinar::factory()->for($series, 'webinarSeries')->create([
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);
        $registration = WebinarRegistration::factory()
            ->for($contact)
            ->for($webinar)
            ->create([
                'status' => 'confirmed',
            ]);
        $enrollment = app(StartWebinarMessageChainEnrollmentAction::class)->handle(
            webinar: $webinar,
            messageAreaKey: 'confirmation',
            recipient: $contact,
            context: $registration,
        );

        $this->assertInstanceOf(MessageChainEnrollment::class, $enrollment);
        $this->assertSame(
            $seriesChain->getKey(),
            $enrollment->messageChainVersion->message_chain_id,
        );

        $oldSeriesVersionId = $seriesChain->current_version_id;
        $confirmationVariant = $seriesChain
            ->requireCurrentVersion()
            ->steps
            ->firstWhere('key', 'confirmation_email')
            ?->variants
            ->firstOrFail();

        $publishedTemplateVersion = app(
            UpdateWebinarSeriesMessageTemplateAction::class,
        )->handle(
            series: $series,
            variant: $confirmationVariant,
            payload: [
                'subject' => 'Series-specific confirmation',
                'body' => 'This confirmation belongs only to Series One.',
            ],
        );

        $seriesChain->refresh();
        $enrollment->refresh();

        $this->assertNotSame(
            (int) $oldSeriesVersionId,
            (int) $seriesChain->current_version_id,
        );
        $this->assertSame(
            (int) $oldSeriesVersionId,
            (int) $enrollment->message_chain_version_id,
        );
        $this->assertStringStartsWith(
            'webinar.series.'.$series->getKey().'.',
            $publishedTemplateVersion->messageTemplate->key,
        );
        $this->assertSame(
            'Series-specific confirmation',
            $seriesChain->requireCurrentVersion()
                ->steps
                ->firstWhere('key', 'confirmation_email')
                ?->variants
                ->firstOrFail()
                ->messageTemplateVersion
                ->subject,
        );
        $this->assertNotSame(
            $publishedTemplateVersion->getKey(),
            $profileChain->requireCurrentVersion()
                ->steps
                ->firstWhere('key', 'confirmation_email')
                ?->variants
                ->firstOrFail()
                ->message_template_version_id,
        );
    }

    public function test_crm_edits_shared_copy_through_the_carousel_and_automatically_creates_series_owned_messages(): void
    {
        [$profile, $profileChain] = $this->registrationProfileAndChain();
        $series = WebinarSeries::factory()->create([
            'title' => 'Editable Series',
            'slug' => 'editable-series',
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);
        $user = User::factory()->create();
        $profileVersionId = $profileChain->current_version_id;
        $sharedVariant = $profileChain
            ->requireCurrentVersion()
            ->steps
            ->firstWhere('key', 'confirmation_email')
            ?->variants
            ->firstOrFail();
        $sharedTemplateVersionId = $sharedVariant->message_template_version_id;

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').route(
                'crm.webinar-series.message-chains.show',
                $series,
                false,
            ))
            ->assertOk()
            ->assertSee('data-webinar-message-ownership="shared"', false)
            ->assertSee('data-message-editor-carousel', false)
            ->assertSee('data-message-editor-published-preview', false)
            ->assertSee('data-message-editor-form', false);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').route(
                'crm.webinar-series.message-chains.variants.update',
                [$series, $sharedVariant],
                false,
            ), [
                '_editing_message_id' => 'variant:'.$sharedVariant->getKey(),
                'payload' => [
                    'subject' => 'Updated from CRM',
                    'body' => 'CRM-owned series copy.',
                ],
            ])
            ->assertRedirect(route(
                'crm.webinar-series.message-chains.show',
                $series,
            ));

        $seriesBinding = WebinarSeriesMessageChainBinding::query()
            ->with('messageChain.currentVersion.steps.variants.messageTemplateVersion')
            ->where('webinar_series_id', $series->getKey())
            ->where('message_area_key', 'confirmation')
            ->firstOrFail();
        $seriesChain = $seriesBinding->messageChain;
        $seriesVariant = $seriesChain
            ->requireCurrentVersion()
            ->steps
            ->firstWhere('key', 'confirmation_email')
            ?->variants
            ->firstOrFail();

        $this->assertNotSame($profileChain->getKey(), $seriesChain->getKey());
        $this->assertSame('Updated from CRM', $seriesVariant->messageTemplateVersion?->subject);

        $profileChain->refresh();
        $this->assertSame($profileVersionId, $profileChain->current_version_id);
        $this->assertSame(
            $sharedTemplateVersionId,
            $profileChain
                ->requireCurrentVersion()
                ->steps
                ->firstWhere('key', 'confirmation_email')
                ?->variants
                ->firstOrFail()
                ->message_template_version_id,
        );

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').route(
                'crm.webinar-series.message-chains.show',
                $series,
                false,
            ))
            ->assertOk()
            ->assertSee('data-webinar-message-ownership="series"', false)
            ->assertSee('Updated from CRM')
            ->assertSee('data-message-editor-form', false);
    }

    public function test_webinar_carousel_can_publish_and_remove_email_media_through_series_copy_on_write(): void
    {
        [$profile, $profileChain] = $this->registrationProfileAndChain();
        $series = WebinarSeries::factory()->create([
            'title' => 'Media Series',
            'slug' => 'media-series',
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);
        $user = User::factory()->create();
        $sharedVariant = $profileChain
            ->requireCurrentVersion()
            ->steps
            ->firstWhere('key', 'confirmation_email')
            ?->variants
            ->firstOrFail();

        Config::set('modules.enabled', ['webinars', 'messaging', 'media']);
        $this->app->instance(MessageMediaLibrary::class, new class implements MessageMediaLibrary {
            public function available(): bool
            {
                return true;
            }

            public function selectableAssets(): array
            {
                return [];
            }

            public function snapshot(string $assetUuid, ?string $posterAssetUuid = null): array
            {
                return [
                    'asset_uuid' => $assetUuid,
                    'kind' => 'image',
                    'title' => 'Webinar image',
                    'url' => 'https://cdn.example.test/webinar.webp',
                    'mime_type' => 'image/webp',
                    'tracking_key' => 'media_primary',
                ];
            }

            public function store(
                UploadedFile $file,
                ?string $title = null,
                ?string $posterAssetUuid = null,
                ?Model $uploadedBy = null,
            ): array {
                throw new \RuntimeException('Upload is not used in this test.');
            }
        });

        $this->withoutMiddleware(ForceStagingAccess::class);
        $this->withoutExceptionHandling();
        
        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').route(
                'crm.webinar-series.message-chains.variants.update',
                [$series, $sharedVariant],
                false,
            ), [
                'return_surface' => 'series_detail',
                'payload' => [
                    'subject' => 'Media confirmation',
                    'body' => "Body\n{media}",
                    'media_present' => '1',
                    'media_asset_uuid' => '11111111-1111-4111-8111-111111111111',
                ],
            ])
            ->assertRedirect(route('crm.webinar-series.show', [
                'series' => $series,
                'messages' => 1,
            ]).'#message-plan');

        $seriesBinding = WebinarSeriesMessageChainBinding::query()
            ->with('messageChain.currentVersion.steps.variants.messageTemplateVersion')
            ->where('webinar_series_id', $series->getKey())
            ->where('message_area_key', 'confirmation')
            ->firstOrFail();
        $seriesVariant = $seriesBinding->messageChain
            ->requireCurrentVersion()
            ->steps
            ->firstWhere('key', 'confirmation_email')
            ?->variants
            ->firstOrFail();

        $this->assertSame(
            '11111111-1111-4111-8111-111111111111',
            data_get($seriesVariant->messageTemplateVersion?->payload(), 'media.asset_uuid'),
        );

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').route(
                'crm.webinar-series.message-chains.variants.update',
                [$series, $seriesVariant],
                false,
            ), [
                'payload' => [
                    'subject' => 'Media removed',
                    'body' => 'Body without media.',
                    'media_present' => '1',
                    'media_asset_uuid' => '',
                ],
            ])
            ->assertRedirect(route('crm.webinar-series.message-chains.show', $series));

        $seriesBinding->messageChain->refresh();
        $publishedVariant = $seriesBinding->messageChain
            ->requireCurrentVersion()
            ->steps
            ->firstWhere('key', 'confirmation_email')
            ?->variants
            ->firstOrFail();

        $this->assertArrayNotHasKey(
            'media',
            $publishedVariant->messageTemplateVersion?->payload() ?? [],
        );
    }

    public function test_series_deletion_removes_unreferenced_owned_chains_and_templates(): void
    {
        [$profile, $profileChain] = $this->registrationProfileAndChain();
        $series = WebinarSeries::factory()->create([
            'title' => 'Disposable Series',
            'slug' => 'disposable-series',
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);

        app(DuplicateWebinarSeriesMessageChainsAction::class)->handle(
            targetSeries: $series,
        );

        $seriesChain = WebinarSeriesMessageChainBinding::query()
            ->with('messageChain.currentVersion.steps.variants')
            ->where('webinar_series_id', $series->getKey())
            ->firstOrFail()
            ->messageChain;
        $variant = $seriesChain
            ->requireCurrentVersion()
            ->steps
            ->firstWhere('key', 'confirmation_email')
            ?->variants
            ->firstOrFail();
        $templateVersion = app(
            UpdateWebinarSeriesMessageTemplateAction::class,
        )->handle(
            series: $series,
            variant: $variant,
            payload: [
                'subject' => 'Disposable confirmation',
                'body' => 'Disposable body.',
            ],
        );
        $seriesChainId = $seriesChain->getKey();
        $seriesTemplateId = $templateVersion->message_template_id;

        app(DeleteWebinarSeriesAction::class)->handle($series);

        $this->assertDatabaseMissing('webinar_series', [
            'id' => $series->getKey(),
        ]);
        $this->assertDatabaseMissing('message_chains', [
            'id' => $seriesChainId,
        ]);
        $this->assertDatabaseMissing('message_templates', [
            'id' => $seriesTemplateId,
        ]);
        $this->assertDatabaseHas('message_chains', [
            'id' => $profileChain->getKey(),
        ]);
    }

    public function test_setup_validation_rejects_an_incomplete_series_owned_binding_set(): void
    {
        [$profile] = $this->registrationProfileAndChain();
        $series = WebinarSeries::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);

        app(DuplicateWebinarSeriesMessageChainsAction::class)->handle(
            targetSeries: $series,
        );

        WebinarSeriesMessageChainBinding::query()
            ->where('webinar_series_id', $series->getKey())
            ->where('message_area_key', 'reminders')
            ->update(['is_active' => false]);

        $codes = collect(
            app(WebinarMessageChainSetupValidationContributor::class)->findings(),
        )->pluck('code');

        $this->assertTrue(
            $codes->contains(
                'webinars.message_chain.series_binding_incomplete',
            ),
        );
    }

    /**
     * @return array{WebinarScheduleProfile, MessageChain}
     */
    private function registrationProfileAndChain(): array
    {
        $profile = WebinarScheduleProfile::query()->create([
            'key' => 'series_fixture',
            'name' => 'Series Fixture',
            'message_template_set_key' => 'default',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
            'is_customized' => false,
            'source' => 'test',
        ]);
        $emailTemplate = MessageTemplate::query()->create([
            'key' => 'email.transactional.webinar.series_fixture.confirmation',
            'name' => 'Series Fixture Confirmation',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $confirmationVersion = app(
            PublishMessageTemplateVersionAction::class,
        )->handle($emailTemplate, [
            'subject' => 'Fixture confirmation',
            'body' => 'Fixture confirmation body.',
        ]);
        $reminderTemplate = MessageTemplate::query()->create([
            'key' => 'email.transactional.webinar.series_fixture.reminder',
            'name' => 'Series Fixture Reminder',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $reminderVersion = app(
            PublishMessageTemplateVersionAction::class,
        )->handle($reminderTemplate, [
            'subject' => 'Fixture reminder',
            'body' => 'Fixture reminder body.',
        ]);
        $chain = MessageChain::query()->create([
            'key' => 'webinar.schedule_profile.series_fixture.registration',
            'name' => 'Series Fixture Registration',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            exitConditions: [
                'any' => [
                    [
                        'field' => 'webinar_registration.cancelled_at',
                        'operator' => 'present',
                    ],
                ],
            ],
            steps: [
                [
                    'key' => 'confirmation_email',
                    'name' => 'Confirmation Email',
                    'sort_order' => 10,
                    'timing_type' => MessageChainStep::TIMING_DELAY,
                    'offset_seconds' => 900,
                    'variants' => [[
                        'key' => 'email',
                        'message_template_version_id' => $confirmationVersion->getKey(),
                        'channel' => 'email',
                        'purpose' => 'transactional',
                        'scope' => 'webinar',
                        'message_type' => 'confirmation',
                        'queue' => 'confirmation_messages',
                    ]],
                ],
                [
                    'key' => 'reminders_email',
                    'name' => 'Reminder Email',
                    'sort_order' => 20,
                    'timing_type' => MessageChainStep::TIMING_ANCHORED,
                    'anchor_key' => 'webinar.starts_at',
                    'offset_seconds' => -3600,
                    'variants' => [[
                        'key' => 'email',
                        'message_template_version_id' => $reminderVersion->getKey(),
                        'channel' => 'email',
                        'purpose' => 'transactional',
                        'scope' => 'webinar',
                        'message_type' => 'reminder',
                        'queue' => 'reminders',
                    ]],
                ],
            ],
        );

        foreach ([
            [
                'message_area_key' => 'confirmation',
                'dispatch_key' => 'registration_created',
            ],
            [
                'message_area_key' => 'reminders',
                'dispatch_key' => 'registration_created',
            ],
        ] as $binding) {
            WebinarScheduleProfileChainBinding::query()->create([
                'webinar_schedule_profile_id' => $profile->getKey(),
                'key' => 'registration',
                'message_area_key' => $binding['message_area_key'],
                'message_chain_id' => $chain->getKey(),
                'dispatch_key' => $binding['dispatch_key'],
                'surface' => 'webinar_registrations',
                'is_active' => true,
            ]);
        }

        return [$profile, $chain->fresh('currentVersion.steps.variants')];
    }
}