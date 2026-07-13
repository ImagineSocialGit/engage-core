<?php

namespace Tests\Feature\SetupValidation;

use App\Modules\Messaging\Validation\MessagingSetupValidationContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessagingSetupValidationContributorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('messaging.email.definitions', []);
        Config::set('messaging.sms.definitions', []);
    }

    public function test_registry_backed_webinar_aliases_are_accepted_by_setup_validation(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [[
                'dispatch_key' => 'registration_created',
                'payload_class' => TestContributorEmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => '{webinar_title}',
                    'body' => "Hi {first_name}.\n{cta}",
                    'cta' => [
                        'label' => 'Join',
                        'url' => '{webinar_join_url}',
                    ],
                ],
            ]],
        ]);

        $tokenFindings = collect(app(MessagingSetupValidationContributor::class)->findings())
            ->filter(fn ($finding): bool => str_contains($finding->message, 'token'));

        $this->assertCount(0, $tokenFindings);
    }

    public function test_reference_config_cannot_make_an_unregistered_token_valid(): void
    {
        Config::set('reference.tokens.models.webinar.aliases', [
            'invented_token' => 'webinar.title',
        ]);

        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [[
                'dispatch_key' => 'registration_created',
                'payload_class' => TestContributorEmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => '{invented_token}',
                    'body' => 'Join us.',
                ],
            ]],
        ]);

        $finding = collect(app(MessagingSetupValidationContributor::class)->findings())
            ->first(fn ($finding): bool => str_contains($finding->message, '{invented_token}'));

        $this->assertNotNull($finding);
        $this->assertSame('error', $finding->severity);
        $this->assertStringContainsString('unknown token', $finding->message);
    }

    public function test_registered_but_unavailable_tokens_block_setup_validation(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [[
                'dispatch_key' => 'registration_created',
                'payload_class' => TestContributorEmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Replay: {webinar_playback_url}',
                ],
            ]],
        ]);

        $finding = collect(app(MessagingSetupValidationContributor::class)->findings())
            ->first(fn ($finding): bool => str_contains($finding->message, '{webinar_playback_url}'));

        $this->assertNotNull($finding);
        $this->assertSame('error', $finding->severity);
        $this->assertStringContainsString('registration_created', $finding->message);
    }
}

class TestContributorEmailPayload
{
    public static function fromArray(array $data): self
    {
        return new self();
    }
}
