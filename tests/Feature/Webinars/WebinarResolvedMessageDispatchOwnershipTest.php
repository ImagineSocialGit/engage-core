<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Services\WebinarScheduleProfileDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarResolvedMessageDispatchOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_profile_item_exclusively_supplies_webinar_behavior_and_owner(): void
    {
        $profile = WebinarScheduleProfile::factory()->create(['is_default' => true]);
        $item = WebinarScheduleProfileItem::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_template_key' => 'confirmation',
            'timing' => 'scheduled',
            'schedule' => ['type' => 'delay', 'minutes' => 10],
            'conditions' => [[
                'field' => 'webinar.starts_at',
                'operator' => 'at_least_minutes_from_now',
                'value' => 40,
            ]],
            'meta' => ['skip_when_join_clicked' => true],
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);

        $resolved = app(WebinarScheduleProfileDefinitionResolver::class)->applyForWebinar(
            webinar: $webinar,
            definitions: [$this->contentOnlyDefinition()],
            dispatchKeys: 'registration_created',
            surface: 'webinar_registrations',
        );

        $this->assertCount(1, $resolved);
        $this->assertSame('scheduled', $resolved[0]['resolved_behavior']['timing']);
        $this->assertSame(['type' => 'delay', 'minutes' => 10], $resolved[0]['resolved_behavior']['schedule']);
        $this->assertEquals($item->conditions, $resolved[0]['resolved_behavior']['conditions']);
        $this->assertTrue($resolved[0]['resolved_behavior']['skip_when_join_clicked']);
        $this->assertTrue($resolved[0]['behavior_owner']->is($item));
    }

    public function test_missing_webinar_profile_never_falls_back_to_template_behavior(): void
    {
        $webinar = Webinar::factory()->create([
            'webinar_schedule_profile_id' => null,
        ]);

        $resolved = app(WebinarScheduleProfileDefinitionResolver::class)->applyForWebinar(
            webinar: $webinar,
            definitions: [$this->contentOnlyDefinition()],
            dispatchKeys: 'registration_created',
            surface: 'webinar_registrations',
        );

        $this->assertSame([], $resolved);
    }

    public function test_unmatched_profile_item_never_falls_back_to_template_behavior(): void
    {
        $profile = WebinarScheduleProfile::factory()->create(['is_default' => true]);
        WebinarScheduleProfileItem::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
            'message_type' => 'reminder',
            'dispatch_key' => 'registration_created',
            'message_template_key' => 'reminder_30_minute',
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);

        $resolved = app(WebinarScheduleProfileDefinitionResolver::class)->applyForWebinar(
            webinar: $webinar,
            definitions: [$this->contentOnlyDefinition()],
            dispatchKeys: 'registration_created',
            surface: 'webinar_registrations',
        );

        $this->assertSame([], $resolved);
    }

    /** @return array<string, mixed> */
    private function contentOnlyDefinition(): array
    {
        return [
            'key' => 'confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'config_path' => 'messaging.email.definitions.transactional.webinar.confirmations.0',
            'payload' => [
                'subject' => 'Registered',
                'body' => 'Thanks',
            ],
            'meta' => [],
        ];
    }
}


