<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\SyncMessageTemplateCompositionLayersAction;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class MessageTemplateCompositionConfigSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_layers_sync_preserve_customized_rows_and_remove_stale_config_rows(): void
    {
        Config::set('client.key', 'composition-test-client');
        Config::set('messaging.composition.layers', [
            'shared_family' => [
                'scope_type' => 'family',
                'channel' => 'email',
                'family_key' => 'reminder',
                'source_version' => 1,
                'payload' => [
                    'cta' => [
                        'label' => 'Configured CTA',
                        'url' => '{webinar_join_url}',
                    ],
                ],
            ],
        ]);

        $action = app(SyncMessageTemplateCompositionLayersAction::class);
        $first = $action->handle();

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $first['updated']);
        $this->assertSame(0, $first['customized_skipped']);
        $this->assertSame(0, $first['stale_removed']);

        $layer = MessageTemplateCompositionLayer::query()->firstOrFail();
        $layer->forceFill([
            'payload' => [
                'cta' => [
                    'label' => 'DB override',
                    'url' => '{webinar_join_url}',
                ],
            ],
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        Config::set('messaging.composition.layers.shared_family.payload.cta.label', 'Changed config');

        $preserved = $action->handle();

        $this->assertSame(1, $preserved['customized_skipped']);
        $this->assertSame('DB override', data_get($layer->fresh()->payload, 'cta.label'));

        $forced = $action->handle(force: true);

        $this->assertSame(1, $forced['updated']);
        $this->assertFalse((bool) $layer->fresh()->is_customized);
        $this->assertSame('Changed config', data_get($layer->fresh()->payload, 'cta.label'));

        Config::set('messaging.composition.layers', []);

        $stale = $action->handle();

        $this->assertSame(1, $stale['stale_removed']);
        $this->assertDatabaseCount('message_template_composition_layers', 0);
    }

    public function test_config_composition_rejects_message_scope_and_duplicate_selector_identity(): void
    {
        Config::set('client.key', 'composition-test-client');
        Config::set('messaging.composition.layers', [
            'message_override' => [
                'scope_type' => 'message',
                'channel' => 'email',
                'payload' => ['subject' => 'Not allowed from config'],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(SyncMessageTemplateCompositionLayersAction::class)->handle();
    }

    public function test_config_composition_rejects_duplicate_family_selector_identity(): void
    {
        Config::set('client.key', 'composition-test-client');
        Config::set('messaging.composition.layers', [
            'first' => [
                'scope_type' => 'family',
                'channel' => 'email',
                'family_key' => 'reminder',
                'payload' => ['subject' => 'One'],
            ],
            'second' => [
                'scope_type' => 'family',
                'channel' => 'email',
                'family_key' => 'reminder',
                'payload' => ['subject' => 'Two'],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(SyncMessageTemplateCompositionLayersAction::class)->handle();
    }
}