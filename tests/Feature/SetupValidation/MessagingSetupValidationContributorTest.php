<?php

namespace Tests\Feature\SetupValidation;

use App\Modules\Messaging\Validation\MessagingSetupValidationContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessagingSetupValidationContributorTest extends TestCase
{
    use RefreshDatabase;

    public function test_documented_webinar_aliases_are_accepted_by_config_validation(): void
    {
        Config::set('reference.tokens.models.webinar.aliases', [
            'webinar_title' => 'webinar.title',
            'webinar_join_url' => 'webinar.join_url',
        ]);

        Config::set('reference.tokens.contexts.registration_created.approved_aliases', [
            'webinar_title',
            'webinar_join_url',
        ]);

        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [[
                'dispatch_key' => 'registration_created',
                'payload_class' => TestContributorEmailPayload::class,
                'queue' => 'confirmation_messages',
                'timing' => 'immediate',
                'payload' => [
                    'subject' => '{webinar_title}',
                    'body' => 'Join us',
                    'cta' => [
                        'label' => 'Join',
                        'url' => '{webinar_join_url}',
                    ],
                ],
            ]],
        ]);

        Config::set('messaging.email.marketing', []);
        Config::set('messaging.email.internal', []);
        Config::set('messaging.sms.transactional', []);
        Config::set('messaging.sms.marketing', []);
        Config::set('messaging.sms.internal', []);

        $warnings = collect(app(MessagingSetupValidationContributor::class)->findings())
            ->filter(fn ($finding): bool => str_contains($finding->code, 'payload_references_token'));

        $this->assertCount(0, $warnings);
    }
}

class TestContributorEmailPayload
{
    public static function fromArray(array $data): self
    {
        return new self();
    }
}
