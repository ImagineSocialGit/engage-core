<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\MessageTokenFallbackResolver;
use Tests\TestCase;

class MessageTokenFallbackResolverTest extends TestCase
{
    public function test_fallback_value_supplies_a_missing_dynamic_field_without_changing_resolved_values(): void
    {
        $resolver = app(MessageTokenFallbackResolver::class);
        $payload = [
            'subject' => 'Hello {first_name}',
            'body' => 'Your appointment is ready.',
            'token_fallbacks' => [[
                'token' => 'first_name',
                'missing_behavior' => MessageTokenFallbackResolver::BEHAVIOR_FALLBACK_VALUE,
                'fallback' => 'there',
            ]],
        ];

        $missing = $resolver->apply($payload);

        $this->assertSame('there', $missing['tokens']['first_name']);
        $this->assertArrayNotHasKey('token_fallbacks', $missing);

        $resolved = $resolver->apply(array_replace_recursive($payload, [
            'tokens' => ['first_name' => 'Taylor'],
        ]));

        $this->assertSame('Taylor', $resolved['tokens']['first_name']);
    }

    public function test_replace_segment_removes_an_optional_personalization_phrase_when_the_field_is_missing(): void
    {
        $payload = app(MessageTokenFallbackResolver::class)->apply([
            'body' => 'Hey {first_name}, Happy birthday!',
            'token_fallbacks' => [[
                'token' => 'first_name',
                'missing_behavior' => MessageTokenFallbackResolver::BEHAVIOR_REPLACE_SEGMENT,
                'segment' => 'Hey {first_name}, ',
                'fallback' => '',
            ]],
        ]);

        $this->assertSame('Happy birthday!', $payload['body']);
        $this->assertArrayNotHasKey('tokens', $payload);
    }

    public function test_required_behavior_leaves_missing_tokens_unresolved_for_the_send_gate(): void
    {
        $payload = app(MessageTokenFallbackResolver::class)->apply([
            'message' => 'Hello :first_name',
            'token_fallbacks' => [[
                'token' => 'first_name',
                'missing_behavior' => MessageTokenFallbackResolver::BEHAVIOR_REQUIRED,
            ]],
        ]);

        $this->assertSame('Hello :first_name', $payload['message']);
        $this->assertArrayNotHasKey('token_fallbacks', $payload);
    }

    public function test_invalid_empty_value_fallback_fails_safe_as_required(): void
    {
        $payload = app(MessageTokenFallbackResolver::class)->apply([
            'subject' => 'Hello {first_name}',
            'token_fallbacks' => [[
                'token' => 'first_name',
                'missing_behavior' => MessageTokenFallbackResolver::BEHAVIOR_FALLBACK_VALUE,
                'fallback' => '',
            ]],
        ]);

        $this->assertSame('Hello {first_name}', $payload['subject']);
        $this->assertArrayNotHasKey('tokens', $payload);
    }

}