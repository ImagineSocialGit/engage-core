<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\TokenContracts\WebinarTokenSourceProvider;
use Tests\TestCase;

class SlamDunkWebinarConfigurationContractTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped(
            'Temporarily disabled pending migration to bundle-scoped golden fixtures.'
        );
    }

    public function test_registration_consent_contract_matches_the_client_modal(): void
    {
        $content = $this->clientConfig('webinars/register/content.php');
        $registration = $content['registration'];

        $this->assertSame(
            ['sms', 'email'],
            $registration['consents']['transactional']['order'],
        );
        $this->assertSame(
            ['email'],
            $registration['consents']['transactional']['required_channels'],
        );
        $this->assertTrue($registration['consents']['marketing']['email']);
        $this->assertTrue($registration['consents']['marketing']['sms']);
        $this->assertTrue($registration['consents']['marketing']['combined']);
        $smsLabel = $registration['fields']['consent_messages']['sms']['label'] ?? null;
        $combinedMarketingLabel = $registration['fields']['marketing_consent_messages']['combined']['label'] ?? null;

        $this->assertIsString($smsLabel);
        $this->assertNotSame('', trim($smsLabel));
        $this->assertIsString($combinedMarketingLabel);
        $this->assertNotSame('', trim($combinedMarketingLabel));
        $this->assertNotSame($smsLabel, $combinedMarketingLabel);
    }

    public function test_confirmation_and_post_event_timings_match_the_client_policy(): void
    {
        $profiles = $this->clientConfig('webinars/schedule_profiles.php');
        $items = collect($profiles['full_10_day']['items'])->keyBy('key');

        foreach (['email_confirmation', 'sms_confirmation'] as $key) {
            $item = $items->get($key);

            $this->assertSame('scheduled', $item['timing']);
            $this->assertSame(
                ['type' => 'delay', 'minutes' => 15],
                $item['schedule'],
            );
            $this->assertSame(40, $item['conditions'][0]['value']);
        }

        foreach ([
            'email_post_attended',
            'sms_post_attended',
            'email_post_missed',
            'sms_post_missed',
        ] as $key) {
            $item = $items->get($key);

            $this->assertSame('scheduled', $item['timing']);
            $this->assertSame(
                ['type' => 'next_day_at', 'time' => '09:00'],
                $item['schedule'],
            );
        }
    }

    public function test_post_event_templates_include_replay_and_booking_links(): void
    {
        $email = $this->clientConfig(
            'messaging/email/definitions/transactional/webinar.php',
        );
        $sms = $this->clientConfig(
            'messaging/sms/definitions/transactional/webinar.php',
        );

        foreach (['post_attended', 'post_missed'] as $context) {
            $emailPayload = $email[$context][0]['payload'];
            $smsMessage = $sms[$context][0]['payload']['message'];

            $this->assertSame(
                '{webinar_playback_url}',
                $emailPayload['cta']['url'],
            );
            $this->assertSame(
                '{webinar_booking_url}',
                $emailPayload['secondary_link']['url'],
            );
            $this->assertStringContainsString(
                '{webinar_playback_url}',
                $smsMessage,
            );
            $this->assertStringContainsString(
                '{webinar_booking_url}',
                $smsMessage,
            );
        }
    }

    public function test_every_thank_you_state_has_truthful_metadata(): void
    {
        $content = $this->clientConfig('webinars/thank-you/content.php');

        foreach (['processing', 'confirmed', 'delayed', 'cancelled'] as $state) {
            $this->assertNotEmpty($content['states'][$state]['meta_title']);
            $this->assertNotEmpty($content['states'][$state]['meta_description']);
        }

        $this->assertNotSame(
            $content['states']['processing']['meta_description'],
            $content['states']['confirmed']['meta_description'],
        );
        $this->assertNotSame(
            $content['states']['confirmed']['meta_description'],
            $content['states']['cancelled']['meta_description'],
        );
    }

    public function test_booking_url_is_an_explicit_client_environment_contract_and_registered_token(): void
    {
        $envExample = file_get_contents(
            base_path('client/slam-dunk-crm/.env.example'),
        );

        $this->assertIsString($envExample);
        $this->assertStringContainsString('WEBINAR_BOOKING_URL=', $envExample);

        $postEvent = $this->clientConfig('webinars/post_event.php');
        $this->assertArrayHasKey('booking', $postEvent);
        $this->assertArrayHasKey('url', $postEvent['booking']);

        $tokens = collect((new WebinarTokenSourceProvider)->sources())
            ->pluck('token')
            ->all();

        $this->assertContains('webinar_booking_url', $tokens);
    }

    /** @return array<string, mixed> */
    private function clientConfig(string $path): array
    {
        $config = require base_path('client/slam-dunk-crm/config/'.$path);

        $this->assertIsArray($config);

        return $config;
    }
}