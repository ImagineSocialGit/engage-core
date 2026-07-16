<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarRegistrationMessageConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_four_selected_consents_schedule_one_email_and_two_sms_messages(): void
    {
        Queue::fake();

        $this->configureConsolidation();
        $this->configureChannelAvailability();
        $this->configureRegistrationDefinitions();
        $this->configureConfirmationScheduleProfile();

        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => null,
            'starts_at' => now()->addDays(2),
        ]);

        $registration = app(CreateWebinarRegistrationAction::class)->handle(
            validated: [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => '(555) 555-0123',
                'transactional_email_consent' => true,
                'transactional_sms_consent' => true,
                'marketing_email_consent' => true,
                'marketing_sms_consent' => true,
            ],
            request: Request::create('/register', 'POST'),
            webinarSlug: $webinar->slug,
        );

        $messages = ScheduledMessage::query()
            ->where('context_type', $registration->getMorphClass())
            ->where('context_id', $registration->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $messages);
        $this->assertSame(1, $messages->where('channel', 'email')->count());
        $this->assertSame(2, $messages->where('channel', 'sms')->count());

        $email = $messages->firstWhere('channel', 'email');
        $transactionalSms = $messages
            ->where('channel', 'sms')
            ->firstWhere('purpose', 'transactional');
        $marketingSms = $messages
            ->where('channel', 'sms')
            ->firstWhere('purpose', 'marketing');

        $this->assertNotNull($email);
        $this->assertNotNull($transactionalSms);
        $this->assertNotNull($marketingSms);

        $this->assertSame('confirmation', $email->message_type);
        $this->assertSame('confirmation', $transactionalSms->message_type);
        $this->assertSame('opt_in', $marketingSms->message_type);

        $this->assertEqualsCanonicalizing([
            'webinar.registration.confirmation',
            'consent.transactional.email.acknowledgement',
            'consent.marketing.email.acknowledgement',
        ], data_get($email->meta, 'delivery_consolidation.intent_keys'));
        $this->assertCount(2, data_get($email->meta, 'delivery_consolidation.consent_ids'));

        $this->assertEqualsCanonicalizing([
            'webinar.registration.confirmation',
            'consent.transactional.sms.acknowledgement',
        ], data_get($transactionalSms->meta, 'delivery_consolidation.intent_keys'));
        $this->assertCount(1, data_get($transactionalSms->meta, 'delivery_consolidation.consent_ids'));

        $this->assertNull(data_get($marketingSms->meta, 'delivery_consolidation'));
        $this->assertSame('marketing', $marketingSms->purpose);
        $this->assertSame('webinar', $marketingSms->scope);

        Queue::assertPushed(SendScheduledMessageJob::class, 3);
    }

    private function configureConsolidation(): void
    {
        $config = require base_path('config/messaging/delivery_consolidation.php');
        data_set($config, 'policies.webinar_registration.enabled', true);

        Config::set('client.name', 'Slam Dunk Home Loans');
        Config::set('messaging.delivery_consolidation', $config);
    }

    private function configureChannelAvailability(): void
    {
        foreach (['email', 'sms'] as $channel) {
            Config::set("messaging.channel_availability.{$channel}", [
                'runtime_supported' => true,
                'provider_enabled' => true,
                'requires_explicit_opt_in' => $channel === 'sms',
                'surfaces' => [
                    'webinar_registrations' => true,
                ],
                'purpose_scopes' => [
                    'transactional:webinar' => true,
                    'marketing:webinar_nurture' => true,
                    'marketing:webinar' => true,
                ],
            ]);
        }
    }

    private function configureRegistrationDefinitions(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [[
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'message_type' => 'confirmation',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Original email confirmation',
                    'body' => 'Original email confirmation body.',
                ],
            ]],
        ]);

        Config::set('messaging.sms.definitions.transactional.webinar', [
            'confirmations' => [[
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'message_type' => 'confirmation',
                'channel' => 'sms',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'payload_class' => SmsPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'message' => 'Original SMS confirmation.',
                ],
            ]],
        ]);
    }

    private function configureConfirmationScheduleProfile(): void
    {
        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'consolidation_test',
            'name' => 'Consolidation Test',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
        ]);

        foreach (['email', 'sms'] as $channel) {
            WebinarScheduleProfileItem::factory()->create([
                'webinar_schedule_profile_id' => $profile->getKey(),
                'key' => "{$channel}_confirmation",
                'context_key' => 'confirmation',
                'channel' => $channel,
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'message_template_key' => 'confirmation',
                'is_enabled' => true,
                'is_active' => true,
                'timing' => 'immediate',
                'schedule' => null,
                'conditions' => [],
            ]);
        }
    }
}
