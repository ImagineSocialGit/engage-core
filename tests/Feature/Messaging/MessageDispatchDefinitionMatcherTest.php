<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\MessageDispatchDefinitionMatcher;
use InvalidArgumentException;
use Tests\TestCase;

class MessageDispatchDefinitionMatcherTest extends TestCase
{
    public function test_it_matches_an_exact_standard_definition_identity(): void
    {
        $matcher = app(MessageDispatchDefinitionMatcher::class);
        $definitions = [
            $this->definition([
                'key' => 'reminder_1_day',
                'definition_key' => 'reminder_1_day',
                'message_type' => 'reminder',
            ]),
            $this->definition([
                'key' => 'reminder_30_minute',
                'definition_key' => 'reminder_30_minute',
                'message_type' => 'reminder',
            ]),
        ];

        $matches = $matcher->matchingDefinitions(
            definitions: $definitions,
            dispatchKeys: $matcher->normalizeDispatchKeys('registration_created'),
            criteria: $matcher->normalizeCriteria([
                'message_type' => 'Reminder',
                'definition_key' => 'reminder-30-minute',
            ]),
        );

        $this->assertCount(1, $matches);
        $this->assertSame('reminder_30_minute', $matches[0]['definition_key']);
    }

    public function test_it_matches_campaign_variant_identity_from_runtime_metadata(): void
    {
        $matcher = app(MessageDispatchDefinitionMatcher::class);
        $definitions = [
            $this->definition([
                'campaign_key' => 'webinar_attended_nurture',
                'step' => 1,
                'variant' => 'email_primary',
                'meta' => [
                    'campaign_template' => [
                        'campaign_key' => 'webinar_attended_nurture',
                        'step_number' => 1,
                        'campaign_step_variant_key' => 'email_primary',
                    ],
                ],
            ]),
            $this->definition([
                'campaign_key' => 'webinar_attended_nurture',
                'step' => 1,
                'variant' => 'email_alternate',
                'meta' => [
                    'campaign_template' => [
                        'campaign_key' => 'webinar_attended_nurture',
                        'step_number' => 1,
                        'campaign_step_variant_key' => 'email_alternate',
                    ],
                ],
            ]),
        ];

        $matches = $matcher->matchingDefinitions(
            definitions: $definitions,
            dispatchKeys: $matcher->normalizeDispatchKeys('campaign_step_due'),
            criteria: $matcher->normalizeCriteria([
                'campaign_key' => 'webinar-attended-nurture',
                'campaign_step' => 1,
                'variant' => 'email-alternate',
            ]),
        );

        $this->assertCount(1, $matches);
        $this->assertSame('email_alternate', $matches[0]['variant']);
    }

    public function test_it_rejects_physical_config_path_criteria(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Dispatch criteria [source_config_path] is not supported',
        );

        app(MessageDispatchDefinitionMatcher::class)->normalizeCriteria([
            'source_config_path' =>
                'messaging.email.definitions.transactional.webinar.reminders.0',
        ]);
    }

    public function test_custom_scalar_criteria_are_matched_instead_of_silently_ignored(): void
    {
        $matcher = app(MessageDispatchDefinitionMatcher::class);
        $definitions = [
            $this->definition([
                'definition_key' => 'primary',
                'meta' => ['delivery_profile' => 'primary'],
            ]),
            $this->definition([
                'definition_key' => 'alternate',
                'meta' => ['delivery_profile' => 'alternate'],
            ]),
        ];

        $matches = $matcher->matchingDefinitions(
            definitions: $definitions,
            dispatchKeys: $matcher->normalizeDispatchKeys('registration_created'),
            criteria: $matcher->normalizeCriteria([
                'meta.delivery_profile' => 'alternate',
            ]),
        );

        $this->assertCount(1, $matches);
        $this->assertSame('alternate', $matches[0]['definition_key']);
    }

    public function test_it_rejects_non_scalar_custom_criteria(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Dispatch criteria [meta.delivery_profile] must be a scalar value or null.');

        app(MessageDispatchDefinitionMatcher::class)->normalizeCriteria([
            'meta.delivery_profile' => ['alternate'],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function definition(array $overrides = []): array
    {
        return array_replace_recursive([
            'dispatch_keys' => ['registration_created', 'campaign_step_due'],
            'message_type' => 'message',
            'payload_class' => 'TestPayload',
            'queue' => 'default',
            'payload' => [],
        ], $overrides);
    }
}