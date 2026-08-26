<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ProcessDueCampaignTouchDatesAction;
use App\Modules\Campaigns\Models\CampaignTouchDate;
use App\Modules\Campaigns\Models\CampaignTouchDispatch;
use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Campaigns\Models\CampaignTouchVariant;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Messaging\Actions\GrantMessageConsentAction;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignAnnualTouchRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_due_birthday_touch_schedules_once_through_messaging_without_campaign_ownership(): void
    {
        Queue::fake();
        config()->set('client.timezone', 'UTC');
        Carbon::setTestNow('2026-08-22 09:05:00 UTC');

        $status = $this->pastClientStatus();

        $contact = Contact::query()->create([
            'first_name' => 'Jamie',
            'email' => 'jamie@example.test',
            'birthday' => '1987-08-22',
        ]);

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $status->getKey(),
            'last_status_changed_at' => now(),
        ]);

        app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => CampaignTouchProgram::MESSAGE_PURPOSE,
            'scope' => CampaignTouchProgram::MESSAGE_SCOPE,
            'source' => 'test',
        ]);

        [$program, $variant] = $this->birthdayVariant();

        $first = app(ProcessDueCampaignTouchDatesAction::class)->handle();
        $second = app(ProcessDueCampaignTouchDatesAction::class)->handle();

        $this->assertSame(1, $first['scheduled']);
        $this->assertSame(0, $second['scheduled']);
        $this->assertDatabaseCount('campaign_touch_dispatches', 1);
        $this->assertDatabaseCount('scheduled_messages', 1);

        $dispatch = CampaignTouchDispatch::query()->firstOrFail();
        $message = ScheduledMessage::query()->firstOrFail();

        $this->assertSame($variant->getKey(), $dispatch->campaign_touch_variant_id);
        $this->assertSame($contact->getKey(), $dispatch->contact_id);
        $this->assertSame(2026, $dispatch->occurrence_year);
        $this->assertSame(CampaignTouchDispatch::STATUS_SCHEDULED, $dispatch->status);
        $this->assertSame($message->getKey(), $dispatch->scheduled_message_id);
        $this->assertEquals([ProcessDueCampaignTouchDatesAction::DISPATCH_KEY], $message->dispatch_keys);
        $this->assertSame($program->getMorphClass(), $message->context_type);
        $this->assertSame($program->getKey(), $message->context_id);
        $this->assertSame($program->getKey(), data_get($dispatch->meta, 'campaign_touch_program_id'));
        $this->assertNull(data_get($dispatch->meta, 'campaign_id'));
        $this->assertNull(data_get($message->meta, 'campaign_key'));
    }

    public function test_touch_program_ignores_wrong_status_and_expired_repeat_window(): void
    {
        Queue::fake();
        config()->set('client.timezone', 'UTC');
        Carbon::setTestNow('2026-08-22 09:05:00 UTC');

        $this->pastClientStatus();
        $wrongStatus = ContactStatus::query()->create([
            'key' => 'engaged',
            'name' => 'Engaged',
            'is_core' => true,
            'is_active' => true,
            'sort_order' => 20,
        ]);

        $contact = Contact::query()->create([
            'first_name' => 'Taylor',
            'email' => 'taylor@example.test',
            'birthday' => '1990-08-22',
        ]);

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $wrongStatus->getKey(),
            'last_status_changed_at' => now(),
        ]);

        [, $variant] = $this->birthdayVariant(
            startsOn: '2020-01-01',
            repeatYears: 3,
        );

        app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => CampaignTouchProgram::MESSAGE_PURPOSE,
            'scope' => CampaignTouchProgram::MESSAGE_SCOPE,
            'source' => 'test',
        ]);

        $result = app(ProcessDueCampaignTouchDatesAction::class)->handle();

        $this->assertSame(0, $result['scheduled']);
        $this->assertDatabaseMissing('campaign_touch_dispatches', [
            'campaign_touch_variant_id' => $variant->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
        $this->assertDatabaseCount('scheduled_messages', 0);
    }

    private function pastClientStatus(): ContactStatus
    {
        return ContactStatus::query()->create([
            'key' => 'past_client',
            'name' => 'Past Client',
            'is_core' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

    /** @return array{0: CampaignTouchProgram, 1: CampaignTouchVariant} */
    private function birthdayVariant(
        string $startsOn = '2026-01-01',
        int $repeatYears = 10,
    ): array {
        $program = CampaignTouchProgram::query()->create([
            'key' => 'past_client_annual_touches',
            'name' => 'Past Client annual touches',
            'audience_type' => CampaignTouchProgram::AUDIENCE_CONTACT_STATUS,
            'audience_key' => 'past_client',
            'recurrence' => CampaignTouchProgram::RECURRENCE_ANNUAL,
            'repeat_years' => $repeatYears,
            'starts_on' => $startsOn,
            'is_active' => true,
        ]);

        $date = CampaignTouchDate::query()->create([
            'campaign_touch_program_id' => $program->getKey(),
            'key' => 'birthday',
            'name' => 'Birthday',
            'source_type' => CampaignTouchDate::SOURCE_CONTACT_FIELD,
            'source_key' => 'birthday',
            'send_time' => '09:00:00',
            'sort_order' => 10,
        ]);

        $preset = MessageTemplatePreset::query()->create([
            'key' => 'past_client_birthday_email',
            'name' => 'Past Client Birthday Email',
            'channel' => 'email',
            'purpose' => CampaignTouchProgram::MESSAGE_PURPOSE,
            'scope' => CampaignTouchProgram::MESSAGE_SCOPE,
            'message_type' => 'birthday',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => [ProcessDueCampaignTouchDatesAction::DISPATCH_KEY],
            'payload' => [
                'subject' => 'Happy birthday',
                'body' => 'Happy birthday, {first_name}!',
            ],
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
        ]);

        $variant = CampaignTouchVariant::query()->create([
            'campaign_touch_date_id' => $date->getKey(),
            'key' => 'email',
            'name' => 'Email',
            'sort_order' => 10,
            'channel' => 'email',
            'purpose' => CampaignTouchProgram::MESSAGE_PURPOSE,
            'scope' => CampaignTouchProgram::MESSAGE_SCOPE,
            'message_template_preset_id' => $preset->getKey(),
            'is_active' => true,
        ]);

        return [$program, $variant];
    }
}