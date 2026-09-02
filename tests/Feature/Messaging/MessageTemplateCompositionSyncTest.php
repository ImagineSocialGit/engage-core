<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageTemplateCompositionSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_assigns_business_composition_identity_groups_post_webinar_outcomes_and_keeps_reply_profile_on_usage(): void
    {
        Config::set('messaging.sms', []);
        Config::set('messaging.email.definitions', [
            'transactional' => [
                'webinar' => [
                    'homebuyer_game_plan' => [
                        'post_attended' => [
                            [
                                'key' => 'post_attended',
                                'dispatch_key' => 'webinar_ended',
                                'reply_profile_key' => 'webinar_homebuyer',
                                'payload_class' => EmailPayload::class,
                                'queue' => 'post_event',
                                'payload' => [
                                    'subject' => 'Thanks for joining',
                                    'body' => 'Thanks for attending.',
                                ],
                            ],
                        ],
                        'post_missed' => [
                            [
                                'key' => 'post_missed',
                                'dispatch_key' => 'webinar_ended',
                                'reply_profile_key' => 'webinar_homebuyer',
                                'payload_class' => EmailPayload::class,
                                'queue' => 'post_event',
                                'payload' => [
                                    'subject' => 'Sorry we missed you',
                                    'body' => 'Here is the replay.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        app(SyncMessageTemplatePresetsAction::class)->handle();

        $templates = MessageTemplate::query()
            ->orderBy('key')
            ->get();

        $this->assertCount(2, $templates);

        foreach ($templates as $template) {
            $this->assertSame('homebuyer_game_plan', $template->composition_context_key);
            $this->assertSame('post_webinar_follow_up', $template->composition_family_key);
        }

        $catalogEntries = MessageTemplateCatalogEntry::query()
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $catalogEntries);
        $this->assertSame(
            $catalogEntries[0]->group_key,
            $catalogEntries[1]->group_key,
        );
        $this->assertNotNull($catalogEntries[0]->group_key);

        $assignments = MessageTemplatePresetAssignment::query()->get();

        $this->assertCount(2, $assignments);
        $this->assertEquals(
            ['webinar_homebuyer'],
            $assignments->pluck('reply_profile_key')->unique()->values()->all(),
        );

        $this->assertFalse(
            in_array(
                'reply_profile_key',
                (new MessageTemplatePreset())->getFillable(),
                true,
            ),
        );

        foreach ($assignments as $assignment) {
            $definition = $assignment
                ->messageTemplatePreset()
                ->firstOrFail()
                ->toMessageDefinition($assignment);

            $this->assertSame(
                'webinar_homebuyer',
                $definition['reply_profile_key'],
            );
        }
    }
}