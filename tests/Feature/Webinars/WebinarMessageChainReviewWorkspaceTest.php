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

    public function test_upcoming_webinar_leads_the_workspace_and_opens_channel_first_message_review(): void
    {
        [$profile] = $this->profileAndChain();
        $series = WebinarSeries::factory()->create([
            'title' => 'Review Fixture Series',
            'slug' => 'review-fixture-series',
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);
        $webinar = Webinar::factory()->for($series, 'webinarSeries')->create([
            'title' => 'Review Fixture Webinar',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('crm.webinar-series.index'));

        $response
            ->assertOk()
            ->assertViewIs('crm.webinars.index')
            ->assertViewHas('upcomingMessageReviews', function ($reviews) use ($webinar): bool {
                $presentation = $reviews->get((int) $webinar->getKey());

                return is_array($presentation)
                    && $presentation['message_count'] === 3
                    && array_keys($presentation['channels']) === ['email', 'sms']
                    && count($presentation['channels']['email']['messages']) === 2
                    && count($presentation['channels']['sms']['messages']) === 1;
            })
            ->assertSee('data-upcoming-webinars', false)
            ->assertSee('data-webinar-message-review-button', false)
            ->assertSee(
                'data-webinar-message-review-modal="'.$webinar->getKey().'"',
                false,
            )
            ->assertSee('data-message-editor-carousel', false)
            ->assertSee('data-message-editor-channel="email"', false)
            ->assertSee('data-message-editor-channel="sms"', false)
            ->assertSee('data-message-editor-published-preview', false)
            ->assertSee('data-message-editor-form', false);

        $html = $response->getContent();
        $upcomingPosition = strpos($html, 'data-upcoming-webinars');
        $workspacePosition = strpos($html, 'data-webinar-workspace-intro');

        $this->assertNotFalse($upcomingPosition);
        $this->assertNotFalse($workspacePosition);
        $this->assertLessThan($workspacePosition, $upcomingPosition);
    }

    public function test_series_message_page_uses_same_editable_carousel_for_shared_and_series_owned_copy(): void
    {
        [$profile] = $this->profileAndChain();
        $series = WebinarSeries::factory()->create([
            'title' => 'Editable Review Series',
            'slug' => 'editable-review-series',
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.webinar-series.message-chains.show', $series))
            ->assertOk()
            ->assertViewHas('messageReview', fn (array $presentation): bool =>
                $presentation['message_count'] === 3
            )
            ->assertSee('data-webinar-message-carousel', false)
            ->assertSee('data-webinar-message-ownership="shared"', false)
            ->assertSee('data-message-editor-carousel', false)
            ->assertSee('data-message-editor-published-preview', false)
            ->assertSee('data-message-editor-form', false);

        app(DuplicateWebinarSeriesMessageChainsAction::class)->handle(
            targetSeries: $series,
            createdBy: $user,
        );

        $this->actingAs($user)
            ->get(route('crm.webinar-series.message-chains.show', $series))
            ->assertOk()
            ->assertSee('data-webinar-message-ownership="series"', false)
            ->assertSee('data-message-editor-carousel', false)
            ->assertSee('data-message-editor-form', false);
    }

    /**
     * @return array{0: WebinarScheduleProfile, 1: MessageChain}
     */
    private function profileAndChain(): array
    {
        $profile = WebinarScheduleProfile::query()->create([
            'key' => 'message_review_fixture',
            'name' => 'Message Review Fixture',
            'message_template_set_key' => 'default',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
            'is_customized' => false,
            'source' => 'test',
        ]);

        $confirmationTemplate = MessageTemplate::query()->create([
            'key' => 'email.transactional.webinar.message_review.confirmation',
            'name' => 'Confirmation Fixture',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $confirmationVersion = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $confirmationTemplate,
            payload: [
                'subject' => 'Confirmation subject',
                'body' => 'Confirmation body.',
            ],
        );

        $reminderTemplate = MessageTemplate::query()->create([
            'key' => 'email.transactional.webinar.message_review.reminder',
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
            'key' => 'sms.transactional.webinar.message_review.reminder',
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
            'key' => 'webinar.schedule_profile.message_review_fixture.registration',
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