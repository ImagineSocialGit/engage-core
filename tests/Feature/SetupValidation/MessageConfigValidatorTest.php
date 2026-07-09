<?php

namespace Tests\Feature\SetupValidation;

use App\Modules\Messaging\Services\MessageConfigValidator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageConfigValidatorTest extends TestCase
{
    public function test_campaign_message_validation_traverses_variant_definitions_instead_of_step_containers(): void
    {
        Config::set('messaging.email.marketing.webinar_nurture', [
            'campaigns' => [
                'webinar_attended_nurture' => [
                    'steps' => [
                        1 => [
                            'variants' => [
                                'email' => [
                                    'dispatch_key' => 'campaign_step_due',
                                    'payload_class' => TestEmailPayload::class,
                                    'queue' => 'marketing',
                                    'payload' => [
                                        'subject' => 'Hello {webinar_title}',
                                        'body' => 'Hi {first_name}',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            allowedTokens: ['webinar_title'],
        );

        $this->assertSame([], array_values(array_filter(
            $issues,
            fn (array $issue): bool => ($issue['level'] ?? null) === 'error',
        )));
    }

    public function test_campaign_message_validation_reports_missing_variants_at_the_variant_path(): void
    {
        Config::set('messaging.email.marketing.webinar_nurture', [
            'campaigns' => [
                'webinar_attended_nurture' => [
                    'steps' => [
                        1 => [],
                    ],
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
        );

        $this->assertTrue(collect($issues)->contains(
            fn (array $issue): bool => ($issue['path'] ?? null)
                === 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants',
        ));
    }
}

class TestEmailPayload
{
    public static function fromArray(array $data): self
    {
        return new self();
    }
}
