<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageTemplateCompositionConfigIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_sync_materializes_config_composition_before_publishing_versions(): void
    {
        Config::set('client.key', 'composition-test-client');
        Config::set('messaging.sms', []);
        Config::set('messaging.composition.layers', [
            'shared_reminder' => [
                'scope_type' => 'family',
                'channel' => 'email',
                'family_key' => 'reminder',
                'source_version' => 1,
                'payload' => [
                    'cta' => [
                        'label' => 'Inherited',
                        'url' => '{webinar_join_url}',
                    ],
                ],
            ],
        ]);
        Config::set('messaging.email.definitions', [
            'transactional' => [
                'webinar' => [
                    'homebuyer_game_plan' => [
                        'reminders' => [
                            [
                                'key' => 'normal',
                                'dispatch_key' => 'registration_created',
                                'message_type' => 'reminder',
                                'payload_class' => EmailPayload::class,
                                'queue' => 'reminders',
                                'payload' => [
                                    'subject' => 'Normal reminder',
                                    'body' => 'Join here: {cta}',
                                ],
                            ],
                            [
                                'key' => 'explicit',
                                'dispatch_key' => 'registration_created',
                                'message_type' => 'reminder',
                                'payload_class' => EmailPayload::class,
                                'queue' => 'reminders',
                                'payload' => [
                                    'subject' => 'Explicit reminder',
                                    'body' => 'Join here: {cta}',
                                    'cta' => [
                                        'label' => 'Explicit',
                                        'url' => '{webinar_join_url}',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(1, $result['composition_layers_created']);

        $templates = MessageTemplate::query()
            ->with('currentVersion')
            ->orderBy('key')
            ->get();

        $this->assertCount(2, $templates);

        $inherited = $templates->first(
            fn (MessageTemplate $template): bool => data_get($template->currentVersion?->payload(), 'cta.label') === 'Inherited',
        );
        $explicit = $templates->first(
            fn (MessageTemplate $template): bool => data_get($template->currentVersion?->payload(), 'cta.label') === 'Explicit',
        );

        $this->assertNotNull($inherited);
        $this->assertNotNull($explicit);
        $this->assertSame('reminder', $inherited->composition_family_key);
        $this->assertSame('reminder', $explicit->composition_family_key);
    }
}