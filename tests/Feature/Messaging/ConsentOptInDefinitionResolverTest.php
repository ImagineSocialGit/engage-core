<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\ConsentOptInDefinitionResolver;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ConsentOptInDefinitionResolverTest extends TestCase
{
    public function test_marketing_email_uses_generic_copy_with_client_name_and_domain_topic(): void
    {
        Config::set('client.name', 'Acme Events');

        $definition = app(ConsentOptInDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'marketing',
            messageScope: 'webinar_nurture',
        );

        $this->assertSame('webinar', $definition['scope']);
        $this->assertSame(EmailPayload::class, $definition['payload_class']);
        $this->assertSame('You’re subscribed', $definition['payload']['subject']);
        $this->assertSame(
            'You’re subscribed to receive marketing emails from Acme Events related to webinars and webinar follow-up. You can unsubscribe at any time.',
            $definition['payload']['body'],
        );
    }

    public function test_marketing_sms_uses_same_domain_topic(): void
    {
        Config::set('client.name', 'Acme Events');

        $definition = app(ConsentOptInDefinitionResolver::class)->resolve(
            channel: 'sms',
            purpose: 'marketing',
            messageScope: 'webinar_waitlist',
        );

        $this->assertSame('webinar', $definition['scope']);
        $this->assertSame(SmsPayload::class, $definition['payload_class']);
        $this->assertStringContainsString('Acme Events', $definition['payload']['message']);
        $this->assertStringContainsString('webinars and webinar follow-up', $definition['payload']['message']);
        $this->assertStringContainsString('Reply STOP to opt out.', $definition['payload']['message']);
    }

    public function test_client_or_module_config_can_override_domain_copy(): void
    {
        Config::set('webinars.consent_domains.webinar.opt_in.email.marketing', [
            'subject' => 'Custom subject',
            'body' => 'Custom :consent_topic copy from :client_name.',
        ]);
        Config::set('client.name', 'Custom Client');

        $definition = app(ConsentOptInDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'marketing',
            messageScope: 'webinar_waitlist',
        );

        $this->assertSame('Custom subject', $definition['payload']['subject']);
        $this->assertSame(
            'Custom webinars and webinar follow-up copy from Custom Client.',
            $definition['payload']['body'],
        );
    }
}
