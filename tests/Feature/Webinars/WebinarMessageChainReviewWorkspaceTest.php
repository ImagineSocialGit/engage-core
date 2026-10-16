<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Webinars\Actions\DuplicateWebinarSeriesMessageChainsAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileChainBinding;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarMessageChainReviewWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('modules.enabled', ['core', 'messaging', 'webinars']);
        Config::set(
            'webinars.message_areas',
            require base_path('config/webinars/message_areas.php'),
        );
    }


    public function test_operator_can_override_and_clear_an_upcoming_webinar_profile(): void
    {
        [$seriesProfile] = $this->profileAndChain(
            profileKey: 'series_profile_update_fixture',
            isDefault: true,
        );
        [$overrideProfile] = $this->profileAndChain(
            profileKey: 'occurrence_profile_update_fixture',
            isDefault: false,
        );
        $series = WebinarSeries::factory()->create([
            'webinar_schedule_profile_id' => $seriesProfile->getKey(),
        ]);
        $webinar = Webinar::factory()->for($series, 'webinarSeries')->create([
            'webinar_schedule_profile_id' => null,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('crm.webinars.schedule-profile.update', $webinar), [
                'webinar_schedule_profile_id' => $overrideProfile->getKey(),
            ])
            ->assertRedirect(route('crm.webinars.show', $webinar));

        $this->assertDatabaseHas('webinars', [
            'id' => $webinar->getKey(),
            'webinar_schedule_profile_id' => $overrideProfile->getKey(),
        ]);

        $this->actingAs($user)
            ->patch(route('crm.webinars.schedule-profile.update', $webinar), [
                'webinar_schedule_profile_id' => null,
            ])
            ->assertRedirect(route('crm.webinars.show', $webinar));

        $this->assertDatabaseHas('webinars', [
            'id' => $webinar->getKey(),
            'webinar_schedule_profile_id' => null,
        ]);
    }


    /**
     * @return array{0: WebinarScheduleProfile, 1: MessageChain}
     */
    private function profileAndChain(
        string $profileKey = 'message_review_fixture',
        bool $isDefault = true,
        string $confirmationSubject = '{webinar_title} — {webinar_start_date}',
    ): array {
        $profile = WebinarScheduleProfile::query()->create([
            'key' => $profileKey,
            'name' => 'Message Review '.str_replace('_', ' ', $profileKey),
            'message_template_set_key' => 'default',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => $isDefault,
            'is_active' => true,
            'is_customized' => false,
            'source' => 'test',
        ]);

        $confirmationTemplate = MessageTemplate::query()->create([
            'key' => 'email.transactional.webinar.'.$profileKey.'.confirmation',
            'name' => 'Confirmation Fixture',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $confirmationVersion = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $confirmationTemplate,
            payload: [
                'subject' => $confirmationSubject,
                'body' => 'Starts {webinar_start_time}. Hi {first_name}.',
            ],
        );

        $reminderTemplate = MessageTemplate::query()->create([
            'key' => 'email.transactional.webinar.'.$profileKey.'.reminder',
            'name' => 'Reminder Fixture',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $reminderVersion = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $reminderTemplate,
            payload: [
                'subject' => 'Reminder subject',
                'body' => 'Reminder body.',
            ],
        );

        $smsTemplate = MessageTemplate::query()->create([
            'key' => 'sms.transactional.webinar.'.$profileKey.'.reminder',
            'name' => 'SMS Reminder Fixture',
            'channel' => 'sms',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $smsVersion = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $smsTemplate,
            payload: [
                'message' => 'SMS reminder body.',
            ],
        );

        $chain = MessageChain::query()->create([
            'key' => 'webinar.schedule_profile.'.$profileKey.'.registration',
            'name' => 'Message Review Registration',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [
                [
                    'key' => 'confirmation',
                    'name' => 'Registration confirmation',
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
                    'key' => 'reminder',
                    'name' => 'One-hour reminder',
                    'sort_order' => 20,
                    'timing_type' => MessageChainStep::TIMING_ANCHORED,
                    'anchor_key' => 'webinar.starts_at',
                    'offset_seconds' => -3600,
                    'variants' => [
                        [
                            'key' => 'email',
                            'message_template_version_id' => $reminderVersion->getKey(),
                            'channel' => 'email',
                            'purpose' => 'transactional',
                            'scope' => 'webinar',
                            'message_type' => 'reminder',
                            'queue' => 'reminders',
                        ],
                        [
                            'key' => 'sms',
                            'message_template_version_id' => $smsVersion->getKey(),
                            'channel' => 'sms',
                            'purpose' => 'transactional',
                            'scope' => 'webinar',
                            'message_type' => 'reminder',
                            'queue' => 'reminders',
                        ],
                    ],
                ],
            ],
        );

        foreach ([
            ['message_area_key' => 'confirmation', 'dispatch_key' => 'registration_created'],
            ['message_area_key' => 'reminders', 'dispatch_key' => 'registration_created'],
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

        return [$profile, $chain->fresh()];
    }
}