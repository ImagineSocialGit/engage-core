<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Actions\SyncWebinarScheduleProfilesAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SyncWebinarScheduleProfilesMessageAreaTest extends TestCase
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

    public function test_sync_disables_non_customized_profile_items_for_disabled_message_areas(): void
    {
        Config::set('webinars.message_areas.post_missed.enabled', false);
        Config::set('webinars.schedule_profiles', [
            'default' => [
                'name' => 'Default',
                'status' => 'active',
                'is_default' => true,
                'is_active' => true,
                'items' => [
                    $this->item(
                        key: 'email_confirmation',
                        contextKey: 'confirmations',
                        messageType: 'confirmation',
                        dispatchKey: 'registration_created',
                        messageTemplateKey: 'confirmation',
                    ),
                    $this->item(
                        key: 'email_post_missed',
                        contextKey: 'post_missed',
                        messageType: 'post_missed',
                        dispatchKey: 'webinar_ended',
                        messageTemplateKey: 'post_missed',
                    ),
                ],
            ],
        ]);

        app(SyncWebinarScheduleProfilesAction::class)->handle();

        $this->assertDatabaseHas('webinar_schedule_profile_items', [
            'key' => 'email_confirmation',
            'is_enabled' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('webinar_schedule_profile_items', [
            'key' => 'email_post_missed',
            'is_enabled' => false,
            'is_active' => true,
        ]);

        $item = \App\Modules\Webinars\Models\WebinarScheduleProfileItem::query()
            ->where('key', 'email_post_missed')
            ->firstOrFail();

        $this->assertSame('post_missed', data_get($item->meta, 'webinar_message_area.key'));
        $this->assertFalse(data_get($item->meta, 'webinar_message_area.enabled'));
    }

    /**
     * @return array<string, mixed>
     */
    private function item(
        string $key,
        string $contextKey,
        string $messageType,
        string $dispatchKey,
        string $messageTemplateKey,
    ): array {
        return [
            'key' => $key,
            'label' => $key,
            'context_key' => $contextKey,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => $messageType,
            'dispatch_key' => $dispatchKey,
            'message_template_key' => $messageTemplateKey,
            'timing' => 'immediate',
            'conditions' => [],
            'is_enabled' => true,
            'is_active' => true,
            'meta' => [],
        ];
    }
}
