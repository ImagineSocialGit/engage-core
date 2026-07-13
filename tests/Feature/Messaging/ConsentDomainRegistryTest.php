<?php

namespace Tests\Feature\Messaging;

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
        $this->assertSame('webinars and webinar follow-up', $registry->topicForDomain('webinar'));
    }

    public function test_undeclared_scope_falls_back_to_itself_without_broadening_consent(): void
    {
        $this->assertSame(
            'future_module_special_notice',
            app(ConsentDomainRegistry::class)->domainForScope('future_module_special_notice'),
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
        $this->expectExceptionMessage('ambiguously matches consent domains');

        app(ConsentDomainRegistry::class)->domainForScope('same_notice');
    }
}
