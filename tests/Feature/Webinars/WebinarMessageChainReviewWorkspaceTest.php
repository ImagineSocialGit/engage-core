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

    public function test_specific_session_surface_keeps_message_review_available(): void
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

        $this->actingAs(User::factory()->create())
            ->get(route('crm.webinars.show', $webinar))
            ->assertOk()
            ->assertViewIs('crm.webinars.show')
            ->assertViewHas('messageReview', function (array $presentation) use ($webinar): bool {
                if (
                    $presentation['message_count'] !== 3
                    || array_keys($presentation['channels']) !== ['email', 'sms']
                    || count($presentation['channels']['email']['messages']) !== 2
                    || count($presentation['channels']['sms']['messages']) !== 1
                ) {
                    return false;
                }

                $message = $presentation['channels']['email']['messages'][0];
                $preview = $message['payload'];
                $editPayload = $message['edit_payload'];

                return str_contains(
                    (string) ($preview['subject'] ?? ''),
                    $webinar->title,
                )
                    && ! str_contains(
                        (string) ($preview['subject'] ?? ''),
                        '{webinar_title}',
                    )
                    && str_contains(
                        (string) ($preview['body'] ?? ''),
                        '{first_name}',
                    )
                    && str_contains(
                        (string) ($editPayload['subject'] ?? ''),
                        '{webinar_title}',
                    )
                    && str_contains(
                        (string) ($editPayload['body'] ?? ''),
                        '{first_name}',
                    );
            })
            ->assertViewHas('messageProfile', function (array $profileData) use ($profile): bool {
                return (int) ($profileData['effective_profile_id'] ?? 0)
                        === (int) $profile->getKey()
                    && (int) ($profileData['inherited_profile_id'] ?? 0)
                        === (int) $profile->getKey()
                    && ($profileData['source'] ?? null) === 'series';
            });
    }

    public function test_occurrence_profile_controls_the_specific_session_message_review(): void
    {
        [$seriesProfile] = $this->profileAndChain(
            profileKey: 'series_review_fixture',
            isDefault: true,
            confirmationSubject: 'Series {webinar_title}',
        );
        [$occurrenceProfile] = $this->profileAndChain(
            profileKey: 'occurrence_review_fixture',
            isDefault: false,
            confirmationSubject: 'Occurrence {webinar_title}',
        );
        $series = WebinarSeries::factory()->create([
            'title' => 'Profile Review Series',
            'slug' => 'profile-review-series',
            'webinar_schedule_profile_id' => $seriesProfile->getKey(),
        ]);
        $webinar = Webinar::factory()->for($series, 'webinarSeries')->create([
            'title' => 'Profile Review Webinar',
            'webinar_schedule_profile_id' => $occurrenceProfile->getKey(),
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('crm.webinars.show', $webinar))
            ->assertOk()
            ->assertViewHas('messageReview', function (array $presentation) use ($webinar): bool {
                $message = $presentation['channels']['email']['messages'][0] ?? [];
                $previewSubject = (string) data_get($message, 'payload.subject', '');
                $editSubject = (string) data_get($message, 'edit_payload.subject', '');

                return str_starts_with($previewSubject, 'Occurrence ')
                    && str_contains($previewSubject, $webinar->title)
                    && ! str_contains($previewSubject, '{webinar_title}')
                    && str_starts_with($editSubject, 'Occurrence ')
                    && str_contains($editSubject, '{webinar_title}');
            })
            ->assertViewHas('messageProfile', function (array $profileData) use (
                $seriesProfile,
                $occurrenceProfile,
            ): bool {
                return (int) ($profileData['effective_profile_id'] ?? 0)
                        === (int) $occurrenceProfile->getKey()
                    && (int) ($profileData['inherited_profile_id'] ?? 0)
                        === (int) $seriesProfile->getKey()
                    && ($profileData['source'] ?? null) === 'occurrence';
            });
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
            ->assertRedirect(route('crm.webinar-series.index', [
                'messages' => $webinar->getKey(),
            ]));

        $this->assertDatabaseHas('webinars', [
            'id' => $webinar->getKey(),
            'webinar_schedule_profile_id' => $overrideProfile->getKey(),
        ]);

        $this->actingAs($user)
            ->patch(route('crm.webinars.schedule-profile.update', $webinar), [
                'webinar_schedule_profile_id' => null,
            ])
            ->assertRedirect(route('crm.webinar-series.index', [
                'messages' => $webinar->getKey(),
            ]));

        $this->assertDatabaseHas('webinars', [
            'id' => $webinar->getKey(),
            'webinar_schedule_profile_id' => null,
        ]);
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