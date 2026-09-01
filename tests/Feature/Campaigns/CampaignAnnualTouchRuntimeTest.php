<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ProcessDueCampaignTouchDatesAction;
use App\Modules\Campaigns\Models\CampaignTouchDate;
use App\Modules\Campaigns\Models\CampaignTouchDispatch;
use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Campaigns\Models\CampaignTouchVariant;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Messaging\Actions\GrantMessageConsentAction;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
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

    public function test_due_birthday_touch_schedules_without_status_or_workflow(): void
    {
        Queue::fake();
        config()->set('client.timezone', 'UTC');
        Carbon::setTestNow('2026-08-22 09:05:00 UTC');

        $contact = Contact::query()->create([
            'first_name' => 'Jamie',
            'email' => 'jamie@example.test',
            'birthday' => '1987-08-22',
        ]);

        $this->grantAnnualTouchEmailConsent($contact);
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

    public function test_fixed_annual_touch_can_reach_contact_without_birthday_or_status(): void
    {
        Queue::fake();
        config()->set('client.timezone', 'UTC');
        Carbon::setTestNow('2026-12-25 10:05:00 UTC');

        $contact = Contact::query()->create([
            'first_name' => 'Morgan',
            'email' => 'morgan@example.test',
        ]);

        $this->grantAnnualTouchEmailConsent($contact);
        [, $variant] = $this->fixedDateVariant(month: 12, day: 25, sendTime: '10:00:00');

        $result = app(ProcessDueCampaignTouchDatesAction::class)->handle();

        $this->assertSame(1, $result['scheduled']);
        $this->assertDatabaseHas('campaign_touch_dispatches', [
            'campaign_touch_variant_id' => $variant->getKey(),
            'contact_id' => $contact->getKey(),
            'occurrence_year' => 2026,
            'status' => CampaignTouchDispatch::STATUS_SCHEDULED,
        ]);
    }

    public function test_tag_audience_and_explicit_exclusion_are_applied_without_workflow(): void
    {
        Queue::fake();
        config()->set('client.timezone', 'UTC');
        Carbon::setTestNow('2026-08-22 09:05:00 UTC');

        $included = Contact::query()->create([
            'first_name' => 'Included',
            'email' => 'included@example.test',
            'birthday' => '1990-08-22',
        ]);
        $excluded = Contact::query()->create([
            'first_name' => 'Excluded',
            'email' => 'excluded@example.test',
            'birthday' => '1991-08-22',
        ]);
        $notTagged = Contact::query()->create([
            'first_name' => 'Outside',
            'email' => 'outside@example.test',
            'birthday' => '1992-08-22',
        ]);

        ContactTag::query()->create(['contact_id' => $included->getKey(), 'tag' => 'VIP']);
        ContactTag::query()->create(['contact_id' => $excluded->getKey(), 'tag' => 'VIP']);

        foreach ([$included, $excluded, $notTagged] as $contact) {
            $this->grantAnnualTouchEmailConsent($contact);
        }

        [, $variant] = $this->birthdayVariant(audienceFilter: [
            'mode' => 'criteria',
            'criteria' => ['tag' => ['VIP']],
            'contact_ids' => [],
            'exclude' => [
                'criteria' => [],
                'contact_ids' => [(int) $excluded->getKey()],
            ],
        ]);

        $result = app(ProcessDueCampaignTouchDatesAction::class)->handle();

        $this->assertSame(1, $result['scheduled']);
        $this->assertDatabaseHas('campaign_touch_dispatches', [
            'campaign_touch_variant_id' => $variant->getKey(),
            'contact_id' => $included->getKey(),
        ]);
        $this->assertDatabaseMissing('campaign_touch_dispatches', [
            'campaign_touch_variant_id' => $variant->getKey(),
            'contact_id' => $excluded->getKey(),
        ]);
        $this->assertDatabaseMissing('campaign_touch_dispatches', [
            'campaign_touch_variant_id' => $variant->getKey(),
            'contact_id' => $notTagged->getKey(),
        ]);
    }

    public function test_touch_program_ignores_expired_repeat_window(): void
    {
        Queue::fake();
        config()->set('client.timezone', 'UTC');
        Carbon::setTestNow('2026-08-22 09:05:00 UTC');

        $contact = Contact::query()->create([
            'first_name' => 'Taylor',
            'email' => 'taylor@example.test',
            'birthday' => '1990-08-22',
        ]);

        [, $variant] = $this->birthdayVariant(
            startsOn: '2020-01-01',
            repeatYears: 3,
        );

        $this->grantAnnualTouchEmailConsent($contact);

        $result = app(ProcessDueCampaignTouchDatesAction::class)->handle();

        $this->assertSame(0, $result['scheduled']);
        $this->assertDatabaseMissing('campaign_touch_dispatches', [
            'campaign_touch_variant_id' => $variant->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
        $this->assertDatabaseCount('scheduled_messages', 0);
    }

    private function grantAnnualTouchEmailConsent(Contact $contact): void
    {
        app(GrantMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => CampaignTouchProgram::MESSAGE_PURPOSE,
            'scope' => CampaignTouchProgram::MESSAGE_SCOPE,
            'source' => 'test',
        ]);
    }

    /** @return array{0: CampaignTouchProgram, 1: CampaignTouchVariant} */
    private function birthdayVariant(
        string $startsOn = '2026-01-01',
        int $repeatYears = 10,
        ?array $audienceFilter = null,
    ): array {
        return $this->variant(
            sourceType: CampaignTouchDate::SOURCE_REGISTERED_DATE,
            sourceKey: 'core.contact.birthday',
            startsOn: $startsOn,
            repeatYears: $repeatYears,
            audienceFilter: $audienceFilter,
        );
    }

    /** @return array{0: CampaignTouchProgram, 1: CampaignTouchVariant} */
    private function fixedDateVariant(
        int $month,
        int $day,
        string $sendTime,
        ?array $audienceFilter = null,
    ): array {
        return $this->variant(
            sourceType: CampaignTouchDate::SOURCE_FIXED_DATE,
            sourceKey: null,
            month: $month,
            day: $day,
            sendTime: $sendTime,
            audienceFilter: $audienceFilter,
        );
    }

    /** @return array{0: CampaignTouchProgram, 1: CampaignTouchVariant} */
    private function variant(
        string $sourceType,
        ?string $sourceKey,
        string $startsOn = '2026-01-01',
        int $repeatYears = 10,
        ?int $month = null,
        ?int $day = null,
        string $sendTime = '09:00:00',
        ?array $audienceFilter = null,
    ): array {
        $program = CampaignTouchProgram::query()->create([
            'key' => 'annual_touch_test',
            'name' => 'Annual touch test',
            'audience_type' => CampaignTouchProgram::AUDIENCE_FILTER,
            'audience_key' => null,
            'audience_filter' => $audienceFilter ?? [
                'mode' => 'all',
                'criteria' => [],
                'contact_ids' => [],
                'exclude' => [
                    'criteria' => [],
                    'contact_ids' => [],
                ],
            ],
            'recurrence' => CampaignTouchProgram::RECURRENCE_ANNUAL,
            'repeat_years' => $repeatYears,
            'starts_on' => $startsOn,
            'is_active' => true,
        ]);

        $date = CampaignTouchDate::query()->create([
            'campaign_touch_program_id' => $program->getKey(),
            'key' => 'annual_date',
            'name' => 'Annual date',
            'source_type' => $sourceType,
            'source_key' => $sourceKey,
            'month' => $month,
            'day' => $day,
            'send_time' => $sendTime,
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $preset = MessageTemplatePreset::query()->create([
            'key' => 'annual_touch_email',
            'name' => 'Annual Touch Email',
            'channel' => 'email',
            'purpose' => CampaignTouchProgram::MESSAGE_PURPOSE,
            'scope' => CampaignTouchProgram::MESSAGE_SCOPE,
            'message_type' => 'annual_touch',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => [ProcessDueCampaignTouchDatesAction::DISPATCH_KEY],
            'payload' => [
                'subject' => 'Annual touch',
                'body' => 'Hello {first_name}',
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