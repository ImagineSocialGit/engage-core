<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\Internal\InternalEmailNotificationPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\ScheduledMessagePayloadCanonicalizer;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class ScheduledMessagePayloadCanonicalizerTest extends TestCase
{
    public function test_it_converts_a_legacy_email_payload_to_the_canonical_shape(): void
    {
        $canonical = $this->canonicalizer()->canonicalizeImportedPayload(
            EmailPayload::class,
            [
                'to' => 'lead@example.com',
                'recipient_type' => 'contact',
                'recipient_id' => 14,
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'message_type' => 'confirmation',
                'contact_id' => 14,
                'first_name' => 'Root Jeff',
                'contact' => [
                    'id' => 14,
                    'first_name' => 'Root Jeff',
                    'email' => 'lead@example.com',
                    'created_at' => '2026-07-24T12:00:00Z',
                ],
                'subject' => 'You are registered',
                'body' => 'Hi {first_name}, you are registered for {webinar.title}. {cta}',
                'cta' => [
                    'label' => 'Join',
                    'url' => '{webinar_join_url}',
                ],
                'runtime_context' => [
                    'webinar' => [
                        'id' => 123,
                        'title' => 'Runtime title',
                    ],
                ],
                'context' => [
                    'webinar' => [
                        'id' => 123,
                        'title' => 'Canonicalization Test',
                        'starts_at' => '2026-08-01T19:00:00-05:00',
                        'recordings' => [
                            ['download_url' => 'https://provider.example.test/raw'],
                        ],
                    ],
                ],
                'tokens' => [
                    'first_name' => 'Jeff',
                    'last_name' => 'Yarnall',
                    'webinar_join_url' => 'https://example.test/join',
                    'contact' => [
                        'id' => 14,
                        'first_name' => 'Jeff',
                        'email' => 'lead@example.com',
                        'updated_at' => '2026-07-24T12:01:00Z',
                    ],
                ],
                'raw_provider_response' => [
                    'registrant' => [
                        'email' => 'lead@example.com',
                    ],
                ],
            ],
            conditions: [
                [
                    'field' => 'webinar.starts_at',
                    'operator' => 'at_least_minutes_from_now',
                    'value' => 30,
                ],
            ],
        );

        $this->assertSame([
            'to' => 'lead@example.com',
            'contact_id' => 14,
            'subject' => 'You are registered',
            'body' => 'Hi {first_name}, you are registered for {webinar.title}. {cta}',
            'cta' => [
                'label' => 'Join',
                'url' => '{webinar_join_url}',
            ],
            'tokens' => [
                'first_name' => 'Jeff',
                'webinar' => [
                    'title' => 'Canonicalization Test',
                    'starts_at' => '2026-08-01T19:00:00-05:00',
                ],
                'webinar_join_url' => 'https://example.test/join',
            ],
        ], $canonical);

        $this->assertArrayNotHasKey('context', $canonical);
        $this->assertArrayNotHasKey('runtime_context', $canonical);
        $this->assertArrayNotHasKey('contact', $canonical);
        $this->assertArrayNotHasKey('raw_provider_response', $canonical);
        $this->assertArrayNotHasKey('last_name', $canonical['tokens']);
        $this->assertArrayNotHasKey('recordings', $canonical['tokens']['webinar']);
    }

    public function test_it_normalizes_sms_aliases_and_projects_nested_tokens(): void
    {
        $canonical = $this->canonicalizer()->canonicalizeImportedPayload(
            SmsPayload::class,
            [
                'phone' => '+15555550123',
                'message_body' => 'Hi :contact.first_name. Join: {webinar_join_url}',
                'context' => [
                    'contact' => [
                        'first_name' => 'Jeff',
                        'email' => 'unused@example.com',
                    ],
                ],
                'tokens' => [
                    'webinar_join_url' => 'https://example.test/join',
                    'unused' => 'discard me',
                ],
                'recipient_type' => 'contact',
                'recipient_id' => 14,
            ],
        );

        $this->assertEquals([
            'to' => '+15555550123',
            'message' => 'Hi :contact.first_name. Join: {webinar_join_url}',
            'tokens' => [
                'contact' => [
                    'first_name' => 'Jeff',
                ],
                'webinar_join_url' => 'https://example.test/join',
            ],
        ], $canonical);
    }

    public function test_persistence_canonicalization_reads_only_the_tokens_container(): void
    {
        Config::set(
            'messaging.sms.definitions.transactional.webinar.confirmation.payload.prefix_brand',
            null,
        );

        $canonical = $this->canonicalizer()->forPersistence(
            payloadClass: SmsPayload::class,
            payload: [
                'to' => '+15555550123',
                'message' => 'Hi {first_name}. {legacy_only} {webinar.title}',
                'legacy_only' => 'Root legacy value',
                'webinar' => [
                    'title' => 'Root legacy webinar',
                ],
                'runtime_context' => [
                    'legacy_only' => 'Runtime legacy value',
                ],
                'context' => [
                    'webinar' => [
                        'title' => 'Context legacy webinar',
                    ],
                ],
                'tokens' => [
                    'first_name' => 'Canonical',
                ],
            ],
            channel: 'sms',
            purpose: 'transactional',
            scope: 'webinar',
            messageType: 'confirmation',
        );

        $this->assertEquals([
            'to' => '+15555550123',
            'message' => 'Hi {first_name}. {legacy_only} {webinar.title}',
            'tokens' => [
                'first_name' => 'Canonical',
            ],
        ], $canonical);
    }

    public function test_it_applies_the_internal_email_allowlist(): void
    {
        $canonical = $this->canonicalizer()->canonicalize(
            InternalEmailNotificationPayload::class,
            [
                'email' => 'operator@example.com',
                'channel' => 'email',
                'purpose' => 'internal',
                'scope' => 'tasks',
                'message_type' => 'task_due',
                'notification_type' => 'task_due',
                'subject' => 'Task due',
                'headline' => 'Action required',
                'body' => [
                    'A task is due.',
                ],
                'details' => [
                    'Priority' => 'High',
                ],
                'cta' => [
                    'label' => 'Open task',
                    'url' => 'https://crm.example.test/tasks/12',
                ],
                'meta' => [
                    'task' => [
                        'entire_model' => str_repeat('x', 5000),
                    ],
                ],
                'tokens' => [
                    'contact' => [
                        'email' => 'operator@example.com',
                    ],
                ],
            ],
        );

        $this->assertSame([
            'to' => 'operator@example.com',
            'notification_type' => 'task_due',
            'subject' => 'Task due',
            'headline' => 'Action required',
            'body' => [
                'A task is due.',
            ],
            'details' => [
                'Priority' => 'High',
            ],
            'cta' => [
                'label' => 'Open task',
                'url' => 'https://crm.example.test/tasks/12',
            ],
        ], $canonical);
    }

    public function test_persistence_canonicalization_freezes_config_fallback_content(): void
    {
        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.subject',
            'Configured subject for {first_name}',
        );
        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.body',
            'Configured body for {webinar.title}',
        );

        $canonical = $this->canonicalizer()->forPersistence(
            payloadClass: EmailPayload::class,
            payload: [
                'to' => 'lead@example.com',
                'tokens' => [
                    'first_name' => 'Jeff',
                    'webinar' => [
                        'title' => 'Stable Webinar',
                        'description' => 'Unused description',
                    ],
                ],
            ],
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            messageType: 'confirmation',
        );

        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.subject',
            'Changed subject',
        );
        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.body',
            'Changed body',
        );

        $this->assertSame(
            'Configured subject for {first_name}',
            $canonical['subject'],
        );
        $this->assertSame(
            'Configured body for {webinar.title}',
            $canonical['body'],
        );
        $this->assertSame([
            'first_name' => 'Jeff',
            'webinar' => [
                'title' => 'Stable Webinar',
            ],
        ], $canonical['tokens']);
    }

    public function test_it_canonicalizes_a_legacy_record_without_mutating_other_fields(): void
    {
        $record = [
            'id' => 99,
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'contact_phone' => '+15555550123',
                'body' => 'Hi {first_name}',
                'first_name' => 'Jeff',
                'contact' => [
                    'email' => 'unused@example.com',
                ],
            ],
        ];

        $canonical = $this->canonicalizer()->canonicalizeImportedRecord($record);

        $this->assertSame(99, $canonical['id']);
        $this->assertSame('reminders', $canonical['queue']);
        $this->assertSame([
            'to' => '+15555550123',
            'message' => 'Hi {first_name}',
            'tokens' => [
                'first_name' => 'Jeff',
            ],
        ], $canonical['payload']);
    }

    public function test_it_rejects_payloads_that_exceed_the_depth_limit(): void
    {
        $nested = 'value';

        for ($depth = 0; $depth <= ScheduledMessagePayloadCanonicalizer::MAX_DEPTH; $depth++) {
            $nested = ['nested' => $nested];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Scheduled message payload exceeds the maximum nesting depth.',
        );

        $this->canonicalizer()->canonicalize(
            EmailPayload::class,
            [
                'to' => 'lead@example.com',
                'subject' => 'Subject',
                'body' => 'Body',
                'cta' => $nested,
            ],
        );
    }

    public function test_it_rejects_payloads_that_exceed_the_encoded_size_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Scheduled message payload exceeds the maximum encoded size.',
        );

        $this->canonicalizer()->canonicalize(
            EmailPayload::class,
            [
                'to' => 'lead@example.com',
                'subject' => str_repeat('s', 30000),
                'body' => str_repeat('b', 30000),
                'footer' => str_repeat('f', 10000),
            ],
        );
    }

    private function canonicalizer(): ScheduledMessagePayloadCanonicalizer
    {
        return new ScheduledMessagePayloadCanonicalizer;
    }
}