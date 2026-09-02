<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageConfigValidator;
use App\Modules\Messaging\Services\MessageDefinitionConfigSetResolver;
use App\Modules\Messaging\Services\MessageTemplatePresetAssignmentResolver;
use App\Modules\Webinars\Actions\SyncWebinarScheduleProfilesAction;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Services\WebinarScheduleProfileDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class WebinarMessageTemplateSetConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_flat_webinar_definition_shape_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(MessageDefinitionConfigSetResolver::class)->sets(
            scope: 'webinar',
            scopeConfig: [
                'confirmations' => [
                    $this->emailDefinition(
                        subject: 'Legacy flat subject',
                        body: 'Legacy flat body.',
                    ),
                ],
            ],
        );
    }

    public function test_named_webinar_files_sync_as_distinct_template_sets(): void
    {
        $this->configureMessageSets();

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, $result['templates_created']);

        $defaultTemplate = MessageTemplate::query()
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();
        $investorTemplate = MessageTemplate::query()
            ->where(
                'key',
                'email.transactional.webinar.investor_strategy.confirmation',
            )
            ->firstOrFail();

        $this->assertNotSame(
            $defaultTemplate->getKey(),
            $investorTemplate->getKey(),
        );
        $this->assertSame(
            'Default fixture subject',
            $defaultTemplate->requireCurrentVersion()->subject,
        );
        $this->assertSame(
            'Investor fixture subject',
            $investorTemplate->requireCurrentVersion()->subject,
        );

        $assignments = MessageTemplatePresetAssignment::query()
            ->orderBy('definition_key')
            ->get();

        $this->assertEquals([
            'confirmation',
            'investor_strategy.confirmation',
        ], $assignments->pluck('definition_key')->all());
        $this->assertEquals([
            'messaging.email.definitions.transactional.webinar.default.confirmations.0',
            'messaging.email.definitions.transactional.webinar.investor-strategy.confirmations.0',
        ], $assignments
            ->pluck('source_config_path')
            ->sort()
            ->values()
            ->all());
        $this->assertEquals([
            'default',
            'investor_strategy',
        ], $assignments
            ->pluck('meta')
            ->map(fn (array $meta): ?string =>
                data_get($meta, 'template_set_key'))
            ->sort()
            ->values()
            ->all());

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertEquals([], $issues);
    }

    public function test_schedule_profiles_select_one_template_set_with_leaf_keys(): void
    {
        $this->configureMessageSets();
        $this->configureScheduleProfiles();

        app(SyncMessageTemplatePresetsAction::class)->handle();
        app(SyncWebinarScheduleProfilesAction::class)->handle();

        $profiles = WebinarScheduleProfile::query()
            ->with('items')
            ->orderBy('key')
            ->get()
            ->keyBy('key');

        $this->assertSame(
            'default',
            $profiles->get('default_fixture')?->message_template_set_key,
        );
        $this->assertSame(
            'investor_strategy',
            $profiles->get('investor_fixture')?->message_template_set_key,
        );

        $definitions = app(
            MessageTemplatePresetAssignmentResolver::class,
        )->resolveDefinitions(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $resolved = app(
            WebinarScheduleProfileDefinitionResolver::class,
        )->applyProfile(
            profile: $profiles->get('investor_fixture'),
            definitions: $definitions,
            dispatchKeys: 'registration_created',
            surface: 'webinar_registrations',
        );

        $this->assertCount(1, $resolved);
        $this->assertSame(
            'investor_strategy',
            $resolved[0]['template_set_key'],
        );
        $this->assertSame('confirmation', $resolved[0]['template_key']);
        $this->assertSame(
            'Investor fixture subject',
            $resolved[0]['payload']['subject'],
        );
        $this->assertSame(
            'investor_strategy',
            data_get(
                $resolved[0],
                'meta.webinar_schedule_profile.message_template_set_key',
            ),
        );
    }

    private function configureMessageSets(): void
    {
        Config::set('messaging.email.definitions', [
            'transactional' => [
                'webinar' => [
                    'default' => [
                        'confirmations' => [
                            $this->emailDefinition(
                                subject: 'Default fixture subject',
                                body: 'Default fixture body.',
                            ),
                        ],
                    ],
                    'investor-strategy' => [
                        'confirmations' => [
                            $this->emailDefinition(
                                subject: 'Investor fixture subject',
                                body: 'Investor fixture body.',
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

    private function configureScheduleProfiles(): void
    {
        Config::set('webinars.schedule_profiles', [
            'default_fixture' => $this->scheduleProfile(
                templateSetKey: 'default',
                isDefault: true,
            ),
            'investor_fixture' => $this->scheduleProfile(
                templateSetKey: 'investor_strategy',
                isDefault: false,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emailDefinition(
        string $subject,
        string $body,
    ): array {
        return [
            'key' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_type' => 'confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'payload' => [
                'subject' => $subject,
                'body' => $body,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleProfile(
        string $templateSetKey,
        bool $isDefault,
    ): array {
        return [
            'name' => 'Fixture '.$templateSetKey,
            'message_template_set_key' => $templateSetKey,
            'status' => 'active',
            'is_default' => $isDefault,
            'is_active' => true,
            'items' => [[
                'key' => 'email_confirmation',
                'label' => 'Fixture confirmation',
                'context_key' => 'confirmation',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'message_template_key' => 'confirmation',
                'timing' => 'immediate',
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
                'sort_order' => 10,
                'meta' => [],
            ]],
        ];
    }
}