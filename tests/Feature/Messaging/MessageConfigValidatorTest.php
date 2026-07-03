<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageConfigValidator;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageConfigValidatorTest extends TestCase
{
    public function test_it_accepts_valid_contact_only_campaign_templates(): void
    {
        Config::set('messaging.email.marketing.webinar_nurture', [
            'campaigns' => [
                'webinar_attended_nurture' => [
                    'steps' => [
                        1 => [
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
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'webinar_relative',
                    'minutes' => 'soon',
                ],
                'payload_class' => 'Missing\\Payload',
                'queue' => '',
                'payload' => [
                    'subject' => '',
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertNotEmpty($issues);
        $this->assertContains('Payload class does not exist.', array_column($issues, 'message'));
        $this->assertContains('Schedule type must be delay or anchored.', array_column($issues, 'message'));
        $this->assertContains('Schedule minutes must be an integer.', array_column($issues, 'message'));
        $this->assertContains('Email payload requires a body.', array_column($issues, 'message'));
    }

    public function test_it_reports_undeclared_payload_tokens_but_accepts_declared_module_tokens(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'timing' => 'immediate',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'You are registered for {webinar_title}',
                    'body' => "Hi {first_name}, use this link.\n{cta}",
                    'cta' => [
                        'label' => 'Join Webinar',
                        'url' => '{next_step_url}',
                    ],
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            allowedTokens: [
                'webinar_title',
            ],
        );

        $messages = array_column($issues, 'message');

        $this->assertContains(
            'Payload references token [{next_step_url}] that is not declared for this config validation context.',
            $messages,
        );

        $this->assertNotContains(
            'Payload references token [{webinar_title}] that is not declared for this config validation context.',
            $messages,
        );

        $this->assertNotContains(
            'Payload references token [{cta}] that is not declared for this config validation context.',
            $messages,
        );
    }

    public function test_it_accepts_valid_sms_with_declared_webinar_tokens(): void
    {
        Config::set('messaging.sms.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 15,
                ],
                'payload_class' => SmsPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'message' => 'You are registered for {webinar_title} on {webinar_start_date} at {webinar_start_time}. Join here: {webinar_join_url}',
                ],
            ],
        ]);

        $issues = app(MessageConfigValidator::class)->validateRoute(
            channel: 'sms',
            purpose: 'transactional',
            scope: 'webinar',
            allowedTokens: [
                'webinar_title',
                'webinar_start_date',
                'webinar_start_time',
                'webinar_join_url',
            ],
        );

        $this->assertSame([], $issues);
    }
}
