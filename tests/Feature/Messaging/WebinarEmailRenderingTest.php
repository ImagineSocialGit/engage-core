<?php

namespace Tests\Feature\Messaging;

use App\Data\WebinarMessageData;
use App\Mail\Webinars\WebinarPostFollowUpMail;
use App\Mail\Webinars\WebinarRegistrationConfirmationMail;
use App\Mail\Webinars\WebinarReminderMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarEmailRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_confirmation_email_renders_transactional_opt_out_url(): void
    {
        $html = (new WebinarRegistrationConfirmationMail(
            data: $this->webinarMessageData(),
            transactionalOptOutUrl: 'https://example.com/email-preferences/transactional/opt-out/test',
        ))->render();

        $this->assertStringContainsString(
            'https://example.com/email-preferences/transactional/opt-out/test',
            $html,
        );
    }

    public function test_reminder_email_renders_transactional_opt_out_url(): void
    {
        $html = (new WebinarReminderMail(
            data: $this->webinarMessageData(),
            messageType: 'reminder_24h',
            subjectLine: 'Your webinar starts soon',
            transactionalOptOutUrl: 'https://example.com/email-preferences/transactional/opt-out/test',
        ))->render();

        $this->assertStringContainsString(
            'https://example.com/email-preferences/transactional/opt-out/test',
            $html,
        );
    }

    public function test_post_follow_up_email_renders_transactional_opt_out_url(): void
    {
        $html = (new WebinarPostFollowUpMail(
            data: $this->webinarMessageData(),
            followUpType: 'replay',
            subjectLine: 'Your webinar replay is ready',
            transactionalOptOutUrl: 'https://example.com/email-preferences/transactional/opt-out/test',
        ))->render();

        $this->assertStringContainsString(
            'https://example.com/email-preferences/transactional/opt-out/test',
            $html,
        );
    }

    private function webinarMessageData(): WebinarMessageData
    {
        return WebinarMessageData::fromArray([
            'registration_id' => 1,
            'lead_id' => 1,
            'lead_first_name' => 'Test',
            'lead_last_name' => 'Lead',
            'lead_email' => 'test@example.com',
            'lead_phone' => '+15555555555',
            'webinar_id' => 1,
            'webinar_slug' => 'test-webinar',
            'webinar_title' => 'Test Webinar',
            'webinar_starts_at' => now()->addDay(),
            'webinar_timezone' => 'America/Chicago',
            'webinar_platform' => 'zoom',
            'webinar_join_url' => 'https://example.com/join',
            'webinar_registration_url' => 'https://example.com/register',
            'request_ip' => '127.0.0.1',
        ]);
    }
}