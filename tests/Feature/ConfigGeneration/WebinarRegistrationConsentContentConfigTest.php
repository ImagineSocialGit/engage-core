<?php

namespace Tests\Feature\ConfigGeneration;

use Tests\TestCase;

class WebinarRegistrationConsentContentConfigTest extends TestCase
{
    public function test_base_and_client_registration_configs_share_the_canonical_consent_shape(): void
    {
        foreach ($this->contentConfigs() as $label => $content) {
            $this->assertSame('Webinar Updates', data_get($content, 'sections.notifications.title'), $label);
            $this->assertSame('Keep Learning — Optional', data_get($content, 'sections.marketing.title'), $label);

            $this->assertSame('Webinar email', data_get($content, 'fields.consent_messages.email.label'), $label);
            $this->assertSame('Webinar SMS', data_get($content, 'fields.consent_messages.sms.label'), $label);
            $this->assertSame('Marketing email', data_get($content, 'fields.marketing_consent_messages.email.label'), $label);
            $this->assertSame('Marketing SMS', data_get($content, 'fields.marketing_consent_messages.sms.label'), $label);

            $this->assertIsString(data_get($content, 'fields.consent_messages.sms.disclosure'), $label);
            $this->assertIsString(data_get($content, 'fields.marketing_consent_messages.sms.disclosure'), $label);
            $this->assertStringContainsString('STOP', data_get($content, 'fields.consent_messages.sms.disclosure'), $label);
            $this->assertStringContainsString('HELP', data_get($content, 'fields.marketing_consent_messages.sms.disclosure'), $label);

            $this->assertSame('Required only when you choose an SMS option.', data_get($content, 'fields.phone.helper'), $label);
            $this->assertNull(data_get($content, 'fields.sections'), $label);
        }
    }

    public function test_rob_registration_config_does_not_publish_placeholder_legal_links(): void
    {
        $content = require base_path('client/rob-the-mortgage-coach/config/webinars/register/content.php');

        $this->assertFalse(data_get($content, 'legal_links.enabled'));
        $this->assertSame([], data_get($content, 'legal_links.links'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function contentConfigs(): array
    {
        return [
            'base' => require base_path('config/webinars/register/content.php'),
            'slam-dunk-crm' => require base_path('client/slam-dunk-crm/config/webinars/register/content.php'),
            'rob-the-mortgage-coach' => require base_path('client/rob-the-mortgage-coach/config/webinars/register/content.php'),
        ];
    }
}
