<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use Tests\TestCase;

class MessageTemplateTokenValidatorTest extends TestCase
{
    public function test_it_accepts_tokens_registered_for_the_dispatch_context_and_structured_render_slots(): void
    {
        $issues = app(MessageTemplateTokenValidator::class)->validatePayload(
            payload: [
                'subject' => 'You are registered for {webinar_title}',
                'body' => "Hi {first_name}, use the button below.\n{cta}",
                'cta' => [
                    'label' => 'Join Webinar',
                    'url' => '{webinar_join_url}',
                ],
            ],
            dispatchKeys: ['registration_created'],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            surface: 'webinar_registrations',
        );

        $this->assertSame([], $issues);
    }

    public function test_it_reports_unknown_tokens_as_hard_errors(): void
    {
        $issues = app(MessageTemplateTokenValidator::class)->validatePayload(
            payload: [
                'subject' => 'Registered',
                'body' => 'Continue here: {not_a_real_token}',
            ],
            dispatchKeys: ['registration_created'],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            surface: 'webinar_registrations',
        );

        $this->assertSame('error', $issues[0]['level']);
        $this->assertSame('payload.body', $issues[0]['path']);
        $this->assertSame(
            'Payload references unknown token [{not_a_real_token}].',
            $issues[0]['message'],
        );
    }

    public function test_it_rejects_registered_tokens_that_are_unavailable_for_the_dispatch_context(): void
    {
        $issues = app(MessageTemplateTokenValidator::class)->validatePayload(
            payload: [
                'subject' => 'Registered',
                'body' => 'Replay: {webinar_playback_url}',
            ],
            dispatchKeys: ['registration_created'],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            surface: 'webinar_registrations',
        );

        $this->assertSame('error', $issues[0]['level']);
        $this->assertSame(
            'Payload references token [{webinar_playback_url}] that is not available for dispatch context [registration_created].',
            $issues[0]['message'],
        );
    }

    public function test_multiple_dispatch_contexts_only_allow_tokens_available_in_every_context(): void
    {
        $issues = app(MessageTemplateTokenValidator::class)->validatePayload(
            payload: [
                'subject' => 'Webinar update',
                'body' => 'Join: {webinar_join_url}',
            ],
            dispatchKeys: [
                'registration_created',
                'webinar_ended',
            ],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            surface: 'webinar_registrations',
        );

        $this->assertSame('error', $issues[0]['level']);
        $this->assertStringContainsString(
            'not available for dispatch context [registration_created, webinar_ended]',
            $issues[0]['message'],
        );
    }

    public function test_it_rejects_dispatch_contexts_that_do_not_match_the_message_route(): void
    {
        $issues = app(MessageTemplateTokenValidator::class)->validatePayload(
            payload: [
                'subject' => 'Campaign message',
                'body' => 'Hi {first_name}',
            ],
            dispatchKeys: ['registration_created'],
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            surface: 'campaigns',
        );

        $this->assertSame('error', $issues[0]['level']);
        $this->assertSame(
            'Dispatch context [registration_created] is not compatible with message route [email:marketing:webinar_nurture:campaigns].',
            $issues[0]['message'],
        );
    }

    public function test_token_extraction_is_shared_and_deduplicated(): void
    {
        $tokens = app(MessageTemplateTokenValidator::class)->tokensFromPayload([
            'subject' => 'Hello {first_name}',
            'body' => 'Again {first_name}. Join {webinar_join_url}.',
            'cta' => [
                'label' => 'Join',
                'url' => '{webinar_join_url}',
            ],
        ]);

        $this->assertSame([
            'first_name',
            'webinar_join_url',
        ], $tokens);
    }

    public function test_it_accepts_multi_cta_collection_for_the_cta_render_slot(): void
    {
        $issues = app(MessageTemplateTokenValidator::class)->validatePayload(
            payload: [
                'subject' => 'Your webinar replay',
                'body' => "Watch the replay below.\n{cta}",
                'ctas' => [
                    [
                        'label' => 'Watch the Recording',
                        'url' => '{webinar_playback_url}',
                    ],
                    [
                        'label' => 'Get Pre-Approved',
                        'url' => 'https://example.test/apply',
                    ],
                ],
            ],
            dispatchKeys: ['webinar_ended'],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            surface: 'webinar_registrations',
        );

        $this->assertSame([], $issues);
    }
}
