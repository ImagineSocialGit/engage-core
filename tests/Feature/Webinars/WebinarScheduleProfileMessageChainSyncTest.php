<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Webinars\Actions\SyncWebinarScheduleProfilesAction;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileChainBinding;
use App\Modules\Webinars\Validation\WebinarMessageChainSetupValidationContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarScheduleProfileMessageChainSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set(
            'webinars.message_areas',
            require base_path('config/webinars/message_areas.php'),
        );
    }

    public function test_profile_sync_publishes_grouped_immutable_chains_and_area_bindings(): void
    {
        $this->configureDefaultRegistrationTemplates();
        Config::set('webinars.schedule_profiles', [
            'fixture_profile' => $this->registrationProfile(
                templateSetKey: 'default',
                confirmationMinutes: 15,
            ),
        ]);

        app(SyncMessageTemplatePresetsAction::class)->handle();
        $result = app(SyncWebinarScheduleProfilesAction::class)->handle();

        $this->assertSame(1, $result['chains_created']);
        $this->assertSame(1, $result['chain_versions_published']);
        $this->assertSame(2, $result['chain_bindings_created']);
        $this->assertSame(0, $result['chains_deferred']);

        $profile = WebinarScheduleProfile::query()
            ->with('messageChainBindings.messageChain.currentVersion.steps.variants')
            ->where('key', 'fixture_profile')
            ->firstOrFail();
        $bindings = $profile->messageChainBindings
            ->keyBy('message_area_key');
        $confirmationBinding = $bindings->get('confirmation');
        $reminderBinding = $bindings->get('reminders');

        $this->assertSame('registration', $confirmationBinding?->key);
        $this->assertSame('registration', $reminderBinding?->key);
        $this->assertSame(
            $confirmationBinding?->message_chain_id,
            $reminderBinding?->message_chain_id,
        );

        $chain = $confirmationBinding?->messageChain;
        $version = $chain?->currentVersion;

        $this->assertInstanceOf(MessageChain::class, $chain);
        $this->assertInstanceOf(MessageChainVersion::class, $version);
        $this->assertSame(
            'webinar.schedule_profile.fixture_profile.registration',
            $chain->key,
        );
        $this->assertTrue($version->isPublished());
        $this->assertCount(2, $version->steps);

        $confirmation = $version->steps
            ->firstWhere('timing_type', MessageChainStep::TIMING_DELAY);
        $reminder = $version->steps
            ->firstWhere('timing_type', MessageChainStep::TIMING_ANCHORED);

        $this->assertSame(900, $confirmation?->offset_seconds);
        $this->assertSame(
            MessageChainStep::VARIANT_STRATEGY_SEND_ALL_ELIGIBLE,
            $confirmation?->variant_strategy,
        );
        $this->assertCount(2, $confirmation?->variants ?? []);
        $this->assertSame('webinar.starts_at', $reminder?->anchor_key);
        $this->assertSame(-1800, $reminder?->offset_seconds);
        $this->assertEquals([[ 
            'field' => 'webinar_registration.join_clicked_at',
            'operator' => 'blank',
        ]], $reminder?->conditions);
        $this->assertCount(2, $reminder?->variants ?? []);

        $expectedTemplateVersionIds = MessageTemplate::query()
            ->whereIn('key', [
                'email.transactional.webinar.confirmation',
                'email.transactional.webinar.reminder_30_minute',
                'sms.transactional.webinar.confirmation',
                'sms.transactional.webinar.reminder_30_minute',
            ])
            ->pluck('current_version_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $actualTemplateVersionIds = $version->steps
            ->flatMap(fn (MessageChainStep $step) => $step->variants)
            ->pluck('message_template_version_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $this->assertEquals(
            $expectedTemplateVersionIds,
            $actualTemplateVersionIds,
        );

        $secondResult = app(
            SyncWebinarScheduleProfilesAction::class,
        )->handle();

        $this->assertSame(0, $secondResult['chain_versions_published']);
        $this->assertSame(1, $secondResult['chain_versions_reused']);
        $this->assertSame(1, MessageChainVersion::query()->count());
    }

    public function test_profiles_with_the_same_leaf_keys_pin_distinct_template_sets(): void
    {
        $this->configureTwoEmailTemplateSets();
        Config::set('webinars.schedule_profiles', [
            'default_fixture' => $this->singleConfirmationProfile(
                templateSetKey: 'default',
                isDefault: true,
            ),
            'investor_fixture' => $this->singleConfirmationProfile(
                templateSetKey: 'investor_strategy',
                isDefault: false,
            ),
        ]);

        app(SyncMessageTemplatePresetsAction::class)->handle();
        app(SyncWebinarScheduleProfilesAction::class)->handle();

        $bindings = WebinarScheduleProfileChainBinding::query()
            ->with('messageChain.currentVersion.steps.variants.messageTemplateVersion')
            ->where('message_area_key', 'confirmation')
            ->orderBy('webinar_schedule_profile_id')
            ->get();

        $this->assertCount(2, $bindings);

        $versionIds = $bindings
            ->map(fn (WebinarScheduleProfileChainBinding $binding): int =>
                (int) $binding->messageChain
                    ->currentVersion
                    ->steps
                    ->firstOrFail()
                    ->variants
                    ->firstOrFail()
                    ->message_template_version_id
            )
            ->all();

        $this->assertNotSame($versionIds[0], $versionIds[1]);
        $this->assertEquals([
            'Default fixture subject',
            'Investor fixture subject',
        ], $bindings
            ->map(fn (WebinarScheduleProfileChainBinding $binding): ?string =>
                $binding->messageChain
                    ->currentVersion
                    ->steps
                    ->firstOrFail()
                    ->variants
                    ->firstOrFail()
                    ->messageTemplateVersion
                    ?->subject
            )
            ->sort()
            ->values()
            ->all());
    }

    public function test_non_webinar_scope_templates_remain_shared_across_series_sets(): void
    {
        $this->configureTwoEmailTemplateSets();
        Config::set('messaging.email.definitions.marketing', [
            'webinar_waitlist' => [
                'alerts' => [[
                    'key' => 'alert',
                    'dispatch_key' => 'webinar_added',
                    'message_type' => 'alert',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_waitlist',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'marketing',
                    'payload' => [
                        'subject' => 'Shared waitlist subject',
                        'body' => 'Shared waitlist body.',
                    ],
                ]],
            ],
        ]);
        $profile = $this->singleConfirmationProfile(
            templateSetKey: 'investor_strategy',
            isDefault: true,
        );
        $profile['items'][] = [
            'key' => 'email_waitlist_alert',
            'label' => 'Fixture waitlist alert',
            'context_key' => 'waitlist',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'surface' => 'webinar_waitlists',
            'message_type' => 'alert',
            'dispatch_key' => 'webinar_added',
            'message_template_key' => 'alert',
            'timing' => 'immediate',
            'conditions' => [],
            'is_enabled' => true,
            'is_active' => true,
            'sort_order' => 100,
            'meta' => [],
        ];
        Config::set('webinars.schedule_profiles', [
            'investor_fixture' => $profile,
        ]);

        app(SyncMessageTemplatePresetsAction::class)->handle();
        app(SyncWebinarScheduleProfilesAction::class)->handle();

        $bindings = WebinarScheduleProfileChainBinding::query()
            ->with('messageChain.currentVersion.steps.variants.messageTemplateVersion')
            ->orderBy('message_area_key')
            ->get()
            ->keyBy('message_area_key');

        $this->assertEquals([
            'confirmation',
            'waitlist',
        ], $bindings->keys()->sort()->values()->all());
        $this->assertSame(
            'Investor fixture subject',
            $bindings->get('confirmation')
                ?->messageChain
                ?->currentVersion
                ?->steps
                ?->firstOrFail()
                ?->variants
                ?->firstOrFail()
                ?->messageTemplateVersion
                ?->subject,
        );
        $this->assertSame(
            'Shared waitlist subject',
            $bindings->get('waitlist')
                ?->messageChain
                ?->currentVersion
                ?->steps
                ?->firstOrFail()
                ?->variants
                ?->firstOrFail()
                ?->messageTemplateVersion
                ?->subject,
        );
    }

    public function test_customized_chain_is_preserved_until_force_sync(): void
    {
        $this->configureDefaultRegistrationTemplates();
        Config::set('webinars.schedule_profiles', [
            'fixture_profile' => $this->registrationProfile(
                templateSetKey: 'default',
                confirmationMinutes: 15,
            ),
        ]);

        app(SyncMessageTemplatePresetsAction::class)->handle();
        app(SyncWebinarScheduleProfilesAction::class)->handle();

        $chain = MessageChain::query()->firstOrFail();
        $originalVersionId = (int) $chain->current_version_id;
        $chain->forceFill([
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        Config::set('webinars.schedule_profiles.fixture_profile',
            $this->registrationProfile(
                templateSetKey: 'default',
                confirmationMinutes: 5,
            ),
        );

        $normalResult = app(
            SyncWebinarScheduleProfilesAction::class,
        )->handle();

        $this->assertSame(1, $normalResult['chains_skipped']);
        $this->assertSame(
            $originalVersionId,
            (int) $chain->refresh()->current_version_id,
        );

        $forceResult = app(
            SyncWebinarScheduleProfilesAction::class,
        )->handle(force: true);

        $chain->refresh()->load('currentVersion.steps.variants');

        $this->assertSame(1, $forceResult['chain_versions_published']);
        $this->assertNotSame(
            $originalVersionId,
            (int) $chain->current_version_id,
        );
        $this->assertFalse($chain->is_customized);
        $this->assertSame(
            300,
            $chain->currentVersion
                ->steps
                ->firstWhere('timing_type', MessageChainStep::TIMING_DELAY)
                ?->offset_seconds,
        );
    }

    public function test_setup_validation_requires_published_chain_bindings(): void
    {
        $this->configureTwoEmailTemplateSets();
        Config::set('webinars.schedule_profiles', [
            'default_fixture' => $this->singleConfirmationProfile(
                templateSetKey: 'default',
                isDefault: true,
            ),
        ]);

        app(SyncMessageTemplatePresetsAction::class)->handle();
        app(SyncWebinarScheduleProfilesAction::class)->handle();

        $this->assertEquals([], collect(app(
            WebinarMessageChainSetupValidationContributor::class,
        )->findings())->pluck('code')->all());

        WebinarScheduleProfileChainBinding::query()->delete();

        $this->assertContains(
            'webinars.message_chain.binding_missing',
            collect(app(
                WebinarMessageChainSetupValidationContributor::class,
            )->findings())->pluck('code')->all(),
        );
    }

    private function configureDefaultRegistrationTemplates(): void
    {
        Config::set('messaging.email.definitions', [
            'transactional' => [
                'webinar' => [
                    'default' => [
                        'confirmations' => [
                            $this->emailDefinition(
                                key: 'confirmation',
                                messageType: 'confirmation',
                                subject: 'Fixture confirmation',
                            ),
                        ],
                        'reminders' => [
                            $this->emailDefinition(
                                key: 'reminder_30_minute',
                                messageType: 'reminder',
                                subject: 'Fixture reminder',
                            ),
                        ],
                    ],
                ],
            ],
            'marketing' => [],
            'internal' => [],
        ]);
        Config::set('messaging.sms.definitions', [
            'transactional' => [
                'webinar' => [
                    'default' => [
                        'confirmations' => [
                            $this->smsDefinition(
                                key: 'confirmation',
                                messageType: 'confirmation',
                                message: 'Fixture confirmation.',
                            ),
                        ],
                        'reminders' => [
                            $this->smsDefinition(
                                key: 'reminder_30_minute',
                                messageType: 'reminder',
                                message: 'Fixture reminder.',
                            ),
                        ],
                    ],
                ],
            ],
            'marketing' => [],
            'internal' => [],
        ]);
    }

    private function configureTwoEmailTemplateSets(): void
    {
        Config::set('messaging.email.definitions', [
            'transactional' => [
                'webinar' => [
                    'default' => [
                        'confirmations' => [
                            $this->emailDefinition(
                                key: 'confirmation',
                                messageType: 'confirmation',
                                subject: 'Default fixture subject',
                            ),
                        ],
                    ],
                    'investor-strategy' => [
                        'confirmations' => [
                            $this->emailDefinition(
                                key: 'confirmation',
                                messageType: 'confirmation',
                                subject: 'Investor fixture subject',
                            ),
                        ],
                    ],
                ],
            ],
            'marketing' => [],
            'internal' => [],
        ]);
        Config::set('messaging.sms.definitions', [
            'transactional' => [],
            'marketing' => [],
            'internal' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emailDefinition(
        string $key,
        string $messageType,
        string $subject,
    ): array {
        return [
            'key' => $key,
            'dispatch_key' => 'registration_created',
            'message_type' => $messageType,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => $messageType === 'confirmation'
                ? 'confirmation_messages'
                : 'reminders',
            'payload' => [
                'subject' => $subject,
                'body' => $subject.' body.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function smsDefinition(
        string $key,
        string $messageType,
        string $message,
    ): array {
        return [
            'key' => $key,
            'dispatch_key' => 'registration_created',
            'message_type' => $messageType,
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => SmsPayload::class,
            'queue' => $messageType === 'confirmation'
                ? 'confirmation_messages'
                : 'reminders',
            'payload' => [
                'message' => $message,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationProfile(
        string $templateSetKey,
        int $confirmationMinutes,
    ): array {
        return [
            'name' => 'Fixture registration profile',
            'message_template_set_key' => $templateSetKey,
            'status' => 'active',
            'is_default' => true,
            'is_active' => true,
            'items' => [
                $this->profileItem(
                    key: 'email_confirmation',
                    contextKey: 'confirmation',
                    channel: 'email',
                    messageType: 'confirmation',
                    templateKey: 'confirmation',
                    sortOrder: 10,
                    timing: 'scheduled',
                    schedule: [
                        'type' => 'delay',
                        'minutes' => $confirmationMinutes,
                    ],
                ),
                $this->profileItem(
                    key: 'sms_confirmation',
                    contextKey: 'confirmation',
                    channel: 'sms',
                    messageType: 'confirmation',
                    templateKey: 'confirmation',
                    sortOrder: 20,
                    timing: 'scheduled',
                    schedule: [
                        'type' => 'delay',
                        'minutes' => $confirmationMinutes,
                    ],
                ),
                $this->profileItem(
                    key: 'email_reminder_30_minute',
                    contextKey: 'reminders',
                    channel: 'email',
                    messageType: 'reminder',
                    templateKey: 'reminder_30_minute',
                    sortOrder: 100,
                    timing: 'scheduled',
                    schedule: [
                        'type' => 'anchored',
                        'minutes' => -30,
                    ],
                    skipWhenJoinClicked: true,
                ),
                $this->profileItem(
                    key: 'sms_reminder_30_minute',
                    contextKey: 'reminders',
                    channel: 'sms',
                    messageType: 'reminder',
                    templateKey: 'reminder_30_minute',
                    sortOrder: 110,
                    timing: 'scheduled',
                    schedule: [
                        'type' => 'anchored',
                        'minutes' => -30,
                    ],
                    skipWhenJoinClicked: true,
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function singleConfirmationProfile(
        string $templateSetKey,
        bool $isDefault,
    ): array {
        return [
            'name' => 'Fixture '.$templateSetKey,
            'message_template_set_key' => $templateSetKey,
            'status' => 'active',
            'is_default' => $isDefault,
            'is_active' => true,
            'items' => [
                $this->profileItem(
                    key: 'email_confirmation',
                    contextKey: 'confirmation',
                    channel: 'email',
                    messageType: 'confirmation',
                    templateKey: 'confirmation',
                    sortOrder: 10,
                    timing: 'immediate',
                    schedule: null,
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $schedule
     * @return array<string, mixed>
     */
    private function profileItem(
        string $key,
        string $contextKey,
        string $channel,
        string $messageType,
        string $templateKey,
        int $sortOrder,
        string $timing,
        ?array $schedule,
        bool $skipWhenJoinClicked = false,
    ): array {
        return array_filter([
            'key' => $key,
            'label' => 'Fixture '.$key,
            'context_key' => $contextKey,
            'channel' => $channel,
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => $messageType,
            'dispatch_key' => 'registration_created',
            'message_template_key' => $templateKey,
            'timing' => $timing,
            'schedule' => $schedule,
            'conditions' => [],
            'is_enabled' => true,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'meta' => $skipWhenJoinClicked
                ? ['skip_when_join_clicked' => true]
                : [],
        ], fn (mixed $value): bool => $value !== null);
    }
}