<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\ConsentDomainRegistry;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class ConsentDomainRegistryTest extends TestCase
{
    public function test_webinar_message_scopes_share_one_consent_domain(): void
    {
        $registry = app(ConsentDomainRegistry::class);

        $this->assertSame('webinar', $registry->domainForScope('webinar'));
        $this->assertSame('webinar', $registry->domainForScope('webinar_waitlist'));
        $this->assertSame('webinar', $registry->domainForScope('webinar_nurture'));
        $this->assertSame('webinar', $registry->domainForScope('webinar_replay_followup'));
        $topic = $registry->topicForDomain('webinar');
        $this->assertIsString($topic);
        $this->assertNotSame('', trim($topic));
    }

    public function test_undeclared_scope_falls_back_to_itself_without_broadening_consent(): void
    {
        $this->assertSame(
            'future_module_special_notice',
            app(ConsentDomainRegistry::class)->domainForScope('future_module_special_notice'),
        );
    }

    public function test_channel_purpose_mapping_can_broaden_one_channel_without_changing_message_scope_identity(): void
    {
        $this->defineMarketingDomain();
        Config::set('messaging.consent.channel_purpose_domains', [
            'email' => [
                'marketing' => 'marketing',
            ],
        ]);

        $registry = app(ConsentDomainRegistry::class);

        $this->assertSame(
            'marketing',
            $registry->domainFor(
                channel: MessageChannel::Email,
                purpose: MessagePurpose::Marketing,
                scope: 'webinar_nurture',
            ),
        );

        $this->assertSame(
            'webinar',
            $registry->domainFor(
                channel: MessageChannel::Sms,
                purpose: MessagePurpose::Marketing,
                scope: 'webinar_nurture',
            ),
        );

        $this->assertSame(
            'webinar',
            $registry->domainFor(
                channel: MessageChannel::Email,
                purpose: MessagePurpose::Transactional,
                scope: 'webinar_nurture',
            ),
        );
    }

    public function test_channel_purpose_mapping_applies_to_future_unknown_marketing_scopes(): void
    {
        $this->defineMarketingDomain();
        Config::set('messaging.consent.channel_purpose_domains.email.marketing', 'marketing');

        $this->assertSame(
            'marketing',
            app(ConsentDomainRegistry::class)->domainFor(
                channel: 'email',
                purpose: 'marketing',
                scope: 'future_marketing_journey_that_does_not_exist_yet',
            ),
        );
    }

    public function test_channel_purpose_mapping_must_reference_a_registered_domain(): void
    {
        Config::set('messaging.consent.channel_purpose_domains.email.marketing', 'missing_domain');

        $issues = app(ConsentDomainRegistry::class)->validationIssues();

        $this->assertContains(
            'messaging.consent_channel_purpose_domain.domain_unknown',
            array_column($issues, 'code'),
        );
    }

    public function test_longest_matching_prefix_wins(): void
    {
        Config::set('modules.modules.example', ['name' => 'Example']);
        Config::set('example.consent_domains', [
            'example' => [
                'topic' => 'example updates',
                'scope_prefixes' => ['example_'],
            ],
            'example_special' => [
                'topic' => 'special example updates',
                'scope_prefixes' => ['example_special_'],
            ],
        ]);

        $this->assertSame(
            'example_special',
            app(ConsentDomainRegistry::class)->domainForScope('example_special_notice'),
        );
    }

    public function test_ambiguous_equal_length_prefixes_fail_loudly(): void
    {
        Config::set('modules.modules.example', ['name' => 'Example']);
        Config::set('example.consent_domains', [
            'first' => [
                'topic' => 'first',
                'scope_prefixes' => ['same_'],
            ],
            'second' => [
                'topic' => 'second',
                'scope_prefixes' => ['same_'],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(ConsentDomainRegistry::class)->domainForScope('same_notice');
    }

    private function defineMarketingDomain(): void
    {
        Config::set('messaging.consent_domains.marketing', [
            'topic' => 'marketing communications',
            'scopes' => [],
            'scope_prefixes' => [],
            'opt_in' => [],
        ]);
    }
}