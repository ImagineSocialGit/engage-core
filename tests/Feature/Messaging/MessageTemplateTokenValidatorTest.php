<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Messaging\Support\MessageMediaPayload;
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

        $this->assertEquals([], $issues);
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
        $this->assertSame('payload.body', $issues[0]['path']);
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
        $this->assertSame('payload.body', $issues[0]['path']);
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
        $this->assertSame('payload', $issues[0]['path']);
    }

    public function test_token_extraction_is_shared_and_deduplicated(): void
    {
        $tokens = app(MessageTemplateTokenValidator::class)->tokensFromPayload([
            'subject' => 'Hello {first_name}',
            'body' => 'Again :first_name. Join {webinar_join_url}.',
            'cta' => [
                'label' => 'Join',
                'url' => '{webinar_join_url}',
            ],
        ]);

        $this->assertEquals([
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

        $this->assertEquals([], $issues);
    }

    public function test_it_accepts_media_render_slot_when_backed_by_valid_media_payload(): void
    {
        $payload = [
            'subject' => 'Example',
            'body' => "Opening.\n{media}\nClosing.",
            'media' => [
                'asset_uuid' => '11111111-1111-4111-8111-111111111111',
                'kind' => 'image',
                'title' => 'Example image',
                'url' => 'https://cdn.example.test/example.webp',
                'mime_type' => 'image/webp',
                'tracking_key' => MessageMediaPayload::TRACKING_KEY,
            ],
        ];

        $validator = app(MessageTemplateTokenValidator::class);

        $issues = $validator->validatePayload(
            payload: $payload,
            dispatchKeys: ['registration_created'],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            surface: 'webinar_registrations',
        );

        $this->assertEquals([], $issues);
        $this->assertNotContains(
            'media',
            $validator->resolvableTokensFromPayload($payload),
        );
    }

    public function test_it_does_not_exempt_media_marker_without_valid_media_payload(): void
    {
        $issues = app(MessageTemplateTokenValidator::class)->validatePayload(
            payload: [
                'subject' => 'Example',
                'body' => 'Open this: {media}',
            ],
            dispatchKeys: ['registration_created'],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            surface: 'webinar_registrations',
        );

        $this->assertSame('error', $issues[0]['level']);
        $this->assertSame('payload.body', $issues[0]['path']);
    }

    public function test_flow_route_send_message_context_exposes_only_runtime_safe_contact_copy_fields(): void
    {
        $validator = app(\App\Modules\Messaging\Services\MessageTemplateTokenValidator::class);

        $this->assertEquals([], $validator->validatePayload(
            payload: ['subject' => 'Hello {first_name}', 'body' => 'Hi {contact.name}'],
            dispatchKeys: ['flow_route_send_message'],
            channel: 'email',
            purpose: 'marketing',
            scope: 'general',
            surface: 'route_send_message_points',
        ));

        $issues = $validator->validatePayload(
            payload: ['subject' => 'Hello', 'body' => 'Join {webinar_title}'],
            dispatchKeys: ['flow_route_send_message'],
            channel: 'email',
            purpose: 'marketing',
            scope: 'general',
            surface: 'route_send_message_points',
        );

        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]['level']);
        $this->assertSame('payload.body', $issues[0]['path']);
    }


    public function test_it_accepts_explicit_fallback_value_and_replace_segment_rules(): void
    {
        $validator = app(MessageTemplateTokenValidator::class);

        $fallbackIssues = $validator->validatePayload(
            payload: [
                'subject' => 'Hello {first_name}',
                'body' => 'Your registration is ready.',
                'token_fallbacks' => [[
                    'token' => 'first_name',
                    'missing_behavior' => 'fallback_value',
                    'fallback' => 'there',
                ]],
            ],
            dispatchKeys: ['registration_created'],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            surface: 'webinar_registrations',
        );

        $segmentIssues = $validator->validatePayload(
            payload: [
                'subject' => 'Registration update',
                'body' => 'Hey {first_name}, your registration is ready.',
                'token_fallbacks' => [[
                    'token' => 'first_name',
                    'missing_behavior' => 'replace_segment',
                    'segment' => 'Hey {first_name}, ',
                    'fallback' => '',
                ]],
            ],
            dispatchKeys: ['registration_created'],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            surface: 'webinar_registrations',
        );

        $this->assertEquals([], $fallbackIssues);
        $this->assertEquals([], $segmentIssues);
    }

    public function test_replace_segment_must_cover_every_use_of_the_optional_field(): void
    {
        $issues = app(MessageTemplateTokenValidator::class)->validatePayload(
            payload: [
                'subject' => 'Hello {first_name}',
                'body' => 'Hey {first_name}, your registration is ready.',
                'token_fallbacks' => [[
                    'token' => 'first_name',
                    'missing_behavior' => 'replace_segment',
                    'segment' => 'Hey {first_name}, ',
                    'fallback' => '',
                ]],
            ],
            dispatchKeys: ['registration_created'],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            surface: 'webinar_registrations',
        );

        $this->assertSame('error', $issues[0]['level']);
        $this->assertSame('payload.token_fallbacks.0.segment', $issues[0]['path']);
    }

    public function test_missing_field_rule_must_reference_a_field_used_by_the_message(): void
    {
        $issues = app(MessageTemplateTokenValidator::class)->validatePayload(
            payload: [
                'subject' => 'Registration update',
                'body' => 'Your registration is ready.',
                'token_fallbacks' => [[
                    'token' => 'first_name',
                    'missing_behavior' => 'fallback_value',
                    'fallback' => 'there',
                ]],
            ],
            dispatchKeys: ['registration_created'],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            surface: 'webinar_registrations',
        );

        $this->assertSame('error', $issues[0]['level']);
        $this->assertSame('payload.token_fallbacks.0.token', $issues[0]['path']);
    }

}