<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageConfigValidator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageConfigValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('messaging.email.definitions', []);
        Config::set('messaging.sms.definitions', []);
    }

    public function test_it_accepts_valid_contact_only_campaign_templates_from_the_campaign_dispatch_context(): void
    {
        Config::set('messaging.email.definitions.marketing.webinar_nurture', [
            'campaigns' => [
                'webinar_attended_nurture' => [
                    'steps' => [
                        1 => [
                            'variants' => [
                                'email' => [
                                    'dispatch_key' => 'campaign_step_due',
                                    'payload_class' => EmailPayload::class,
                                    'queue' => 'marketing',
                                    'payload' => [
                                        'subject' => 'Thanks for joining',
                                        'body' => 'Hi {first_name}, reply with your biggest question.',
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
        );

        $this->assertSame([], $issues);
    }

    public function test_it_reports_invalid_definition_shape(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [
                [
                    'dispatch_key' => 'registration_created',
                    'payload_class' => 'Missing\\Payload',
                    'queue' => '',
                    'payload' => [
                        'subject' => '',
                    ],
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $messages = array_column($issues, 'message');

        $this->assertContains('Payload class does not exist.', $messages);
        $this->assertContains('Message definition has invalid [queue].', $messages);
        $this->assertContains('Email payload requires a body.', $messages);
    }

    public function test_it_reports_unknown_payload_tokens_as_errors_without_a_caller_allowlist(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [
                [
                    'dispatch_key' => 'registration_created',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'confirmation_messages',
                    'payload' => [
                        'subject' => 'Registered for {webinar_title}',
                        'body' => 'Continue here: {next_step_url}',
                    ],
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $tokenIssue = collect($issues)->first(
            fn (array $issue): bool => str_contains($issue['message'], '{next_step_url}'),
        );

        $this->assertNotNull($tokenIssue);
        $this->assertSame('error', $tokenIssue['level']);
        $this->assertSame(
            'messaging.email.definitions.transactional.webinar.confirmations.0.payload.body',
            $tokenIssue['path'],
        );
    }

    public function test_it_accepts_webinar_tokens_registered_for_registration_created_and_render_slots(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [
                [
                    'dispatch_key' => 'registration_created',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'confirmation_messages',
                    'payload' => [
                        'subject' => 'You are registered for {webinar_title}',
                        'body' => "Hi {first_name}, your webinar starts on {webinar_start_date} at {webinar_start_time}.\n{cta}",
                        'cta' => [
                            'label' => 'Join Webinar',
                            'url' => '{webinar_join_url}',
                        ],
                    ],
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertSame([], $issues);
    }

    public function test_it_rejects_post_event_token_from_registration_created_context(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [
                [
                    'dispatch_key' => 'registration_created',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'confirmation_messages',
                    'payload' => [
                        'subject' => 'Registered',
                        'body' => 'Replay: {webinar_playback_url}',
                    ],
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertTrue(collect($issues)->contains(
            fn (array $issue): bool => ($issue['level'] ?? null) === 'error'
                && str_contains($issue['message'], '{webinar_playback_url}')
                && str_contains($issue['message'], 'registration_created'),
        ));
    }

    public function test_it_accepts_waitlist_alert_tokens_from_webinar_added_context(): void
    {
        Config::set('messaging.email.definitions.marketing.webinar_waitlist', [
            'alerts' => [
                [
                    'dispatch_key' => 'webinar_added',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'notifications',
                    'payload' => [
                        'subject' => 'New session: {webinar_title}',
                        'body' => 'Register here: {webinar_registration_url}',
                    ],
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_waitlist',
        );

        $this->assertSame([], $issues);
    }

    public function test_it_accepts_valid_sms_with_registry_backed_webinar_tokens(): void
    {
        Config::set('messaging.sms.definitions.transactional.webinar', [
            'confirmations' => [
                [
                    'dispatch_key' => 'registration_created',
                    'payload_class' => SmsPayload::class,
                    'queue' => 'confirmation_messages',
                    'payload' => [
                        'message' => 'You are registered for {webinar_title} on {webinar_start_date} at {webinar_start_time}. Join: {webinar_join_url}',
                    ],
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'sms',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertSame([], $issues);
    }
}
