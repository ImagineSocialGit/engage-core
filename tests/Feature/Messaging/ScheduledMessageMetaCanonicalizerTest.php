<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\ScheduledMessageMetaCanonicalizer;
use InvalidArgumentException;
use Tests\TestCase;

class ScheduledMessageMetaCanonicalizerTest extends TestCase
{
    public function test_persistence_keeps_only_operational_metadata_and_compact_provenance(): void
    {
        $canonical = $this->canonicalizer()->forPersistence([
            'queue' => 'notifications',
            'dispatch_keys' => ['registration_created'],
            'definition_config_path' => 'messaging.email.definitions.transactional.webinar.confirmation',
            'message_scheduling' => [
                'source_timezone' => 'America/Chicago',
                'source_send_at' => '2026-07-24T09:00:00-05:00',
                'utc_send_at' => '2026-07-24T14:00:00+00:00',
            ],
            'resolved_message_dispatch' => [
                'resolved_send_at' => '2026-07-24T14:00:00+00:00',
                'behavior_owner_type' => 'webinar_schedule_profile_item',
                'behavior_owner_id' => 41,
            ],
            'conditions' => [[
                'field' => 'webinar.starts_at',
                'operator' => 'at_least_minutes_from_now',
                'value' => 30,
                'raw_provider_payload' => ['secret' => true],
            ]],
            'skip_when_join_clicked' => true,
            'campaign_enrollment_id' => 21,
            'campaign_id' => 22,
            'campaign_key' => 'webinar_attended',
            'campaign_step_id' => 23,
            'campaign_step' => 2,
            'campaign_step_variant_id' => 24,
            'campaign_step_variant_key' => 'email_primary',
            'campaign_template' => [
                'campaign_key' => 'duplicated',
                'payload' => ['entire' => 'definition'],
            ],
            'message_template_preset' => [
                'id' => 31,
                'key' => 'email.transactional.webinar.confirmation',
                'assignment_id' => 32,
                'source_config_path' => 'duplicated.path',
                'catalog' => ['item_label' => 'Confirmation Email'],
            ],
            'message_template_assignment' => [
                'id' => 32,
                'definition_key' => 'confirmation',
                'source_config_path' => 'duplicated.path',
            ],
            'webinar_schedule_profile' => [
                'id' => 51,
                'key' => 'default',
                'name' => 'Default Webinar Schedule',
                'item_id' => 52,
                'item_key' => 'email_confirmation',
                'item_label' => 'Email Confirmation',
            ],
            'webinar_message_area' => [
                'key' => 'confirmation',
                'label' => 'Confirmation',
            ],
            'webinar_registration_id' => 61,
            'webinar_id' => 62,
            'webinar_series_id' => 63,
            'contact' => [
                'id' => 99,
                'email' => 'private@example.com',
            ],
            'provider' => [
                'access_token' => 'secret',
            ],
        ]);

        $this->assertEquals([
            'campaign_enrollment_id' => 21,
            'campaign_id' => 22,
            'campaign_step_id' => 23,
            'campaign_step' => 2,
            'campaign_step_variant_id' => 24,
            'webinar_registration_id' => 61,
            'webinar_id' => 62,
            'webinar_series_id' => 63,
            'campaign_key' => 'webinar_attended',
            'campaign_step_variant_key' => 'email_primary',
            'skip_when_join_clicked' => true,
            'conditions' => [[
                'field' => 'webinar.starts_at',
                'operator' => 'at_least_minutes_from_now',
                'value' => 30,
            ]],
            'message_template' => [
                'preset_id' => 31,
                'preset_key' => 'email.transactional.webinar.confirmation',
                'assignment_id' => 32,
                'definition_key' => 'confirmation',
            ],
            'webinar_schedule' => [
                'profile_id' => 51,
                'profile_key' => 'default',
                'item_id' => 52,
                'item_key' => 'email_confirmation',
                'area_key' => 'confirmation',
            ],
        ], $canonical);

        $this->assertLessThanOrEqual(
            ScheduledMessageMetaCanonicalizer::MAX_ENCODED_BYTES,
            strlen(json_encode($canonical, JSON_THROW_ON_ERROR)),
        );
    }

    public function test_runtime_canonicalization_does_not_promote_import_only_aliases(): void
    {
        $canonical = $this->canonicalizer()->canonicalize([
            'source' => 'automation',
            'message_template_preset' => [
                'id' => 11,
                'key' => 'legacy-template',
            ],
            'webinar_schedule_profile' => [
                'id' => 12,
                'key' => 'legacy-profile',
            ],
            'message_scheduling' => [
                'utc_send_at' => '2026-07-24T14:00:00+00:00',
            ],
            'resolved_message_dispatch' => [
                'behavior_owner_id' => 13,
            ],
        ]);

        $this->assertEquals([
            'source' => 'automation',
        ], $canonical);
    }

    public function test_import_methods_convert_legacy_metadata_and_promote_column_identity(): void
    {
        $record = $this->canonicalizer()->canonicalizeImportedRecord([
            'payload_class' => 'LegacyPayload',
            'metadata' => [
                'queue' => 'reminders',
                'dispatch_keys' => ['registration-created', 'registration-created'],
                'definition_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.0',
                'message_scheduling' => [
                    'utc_send_at' => '2026-07-24T14:00:00+00:00',
                ],
                'resolved_message_dispatch' => [
                    'behavior_owner_type' => 'webinar_schedule_profile_item',
                    'behavior_owner_id' => 71,
                ],
                'message_template_preset' => [
                    'id' => 72,
                    'key' => 'email.transactional.webinar.reminder',
                    'assignment_id' => 73,
                    'source_config_path' => 'duplicated.path',
                ],
                'webinar_schedule_profile' => [
                    'id' => 74,
                    'key' => 'default',
                    'item_id' => 75,
                    'item_key' => 'email_reminder',
                    'name' => 'Discarded label',
                ],
                'webinar_message_area' => [
                    'key' => 'reminders',
                    'label' => 'Discarded label',
                ],
            ],
        ]);

        $this->assertSame('reminders', $record['queue']);
        $this->assertEquals(
            ['registration-created'],
            $record['dispatch_keys'],
        );
        $this->assertSame(
            'messaging.email.definitions.transactional.webinar.reminders.0',
            $record['definition_config_path'],
        );
        $this->assertSame(
            'webinar_schedule_profile_item',
            $record['behavior_owner_type'],
        );
        $this->assertSame(71, $record['behavior_owner_id']);
        $this->assertSame(
            '2026-07-24T14:00:00+00:00',
            $record['send_at'],
        );
        $this->assertEquals([
            'message_template' => [
                'preset_id' => 72,
                'preset_key' => 'email.transactional.webinar.reminder',
                'assignment_id' => 73,
            ],
            'webinar_schedule' => [
                'profile_id' => 74,
                'profile_key' => 'default',
                'item_id' => 75,
                'item_key' => 'email_reminder',
                'area_key' => 'reminders',
            ],
        ], $record['meta']);
        $this->assertArrayNotHasKey('metadata', $record);
    }

    public function test_consolidation_and_consent_metadata_are_bounded_and_canonical(): void
    {
        $canonical = $this->canonicalizer()->forPersistence([
            'consent_policy' => [
                'permission_invitation' => [
                    'source' => 'imported_contact',
                    'one_time' => true,
                    'raw_contact' => ['email' => 'private@example.com'],
                ],
            ],
            'delivery_intent' => [
                'key' => 'discarded_after_consolidation',
            ],
            'resolver_context' => [
                'webinar' => ['entire' => 'graph'],
            ],
            'delivery_consolidation' => [
                'policy' => 'webinar_registration',
                'group' => 'email_acknowledgements',
                'intent_keys' => [
                    'webinar.registration.confirmation',
                    'consent.transactional.email.acknowledgement',
                    'webinar.registration.confirmation',
                ],
                'consent_ids' => [10, '11', 10],
                'primary_intent_key' => 'webinar.registration.confirmation',
                'template_key' => 'confirmation',
                'template_source' => 'primary_intent',
                'payload_key' => 'body',
                'position' => 'append',
                'separator' => "\n\n",
                'fragment_tokens' => [
                    'delivery_consolidation_webinar_email_acknowledgement',
                ],
                'raw_intents' => [
                    ['recipient' => ['email' => 'private@example.com']],
                ],
            ],
        ]);

        $this->assertEquals([
            'consent_policy' => [
                'permission_invitation' => [
                    'source' => 'imported_contact',
                    'one_time' => true,
                ],
            ],
            'delivery_consolidation' => [
                'policy' => 'webinar_registration',
                'group' => 'email_acknowledgements',
                'primary_intent_key' => 'webinar.registration.confirmation',
                'template_key' => 'confirmation',
                'template_source' => 'primary_intent',
                'payload_key' => 'body',
                'position' => 'append',
                'separator' => "\n\n",
                'intent_keys' => [
                    'webinar.registration.confirmation',
                    'consent.transactional.email.acknowledgement',
                ],
                'fragment_tokens' => [
                    'delivery_consolidation_webinar_email_acknowledgement',
                ],
                'consent_ids' => [10, 11],
            ],
        ], $canonical);
    }

    public function test_delivery_metadata_remains_available_until_delivery_diagnostics_batch(): void
    {
        $canonical = $this->canonicalizer()->canonicalize([
            'delivery' => [
                'status' => 'failed',
                'attempt' => 2,
                'provider' => 'test_provider',
                'recovery' => [
                    'count' => 1,
                    'recovered_at' => '2026-07-24T14:00:00+00:00',
                ],
            ],
        ]);

        $this->assertEquals([
            'delivery' => [
                'status' => 'failed',
                'attempt' => 2,
                'provider' => 'test_provider',
                'recovery' => [
                    'count' => 1,
                    'recovered_at' => '2026-07-24T14:00:00+00:00',
                ],
            ],
        ], $canonical);
    }

    public function test_oversized_preserved_metadata_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum encoded size');

        $this->canonicalizer()->canonicalize([
            'delivery' => [
                'provider_evidence_1' => str_repeat('a', 4000),
                'provider_evidence_2' => str_repeat('b', 4000),
                'provider_evidence_3' => str_repeat('c', 4000),
                'provider_evidence_4' => str_repeat('d', 4000),
                'provider_evidence_5' => str_repeat('e', 4000),
            ],
        ]);
    }

    public function test_excessively_nested_preserved_metadata_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum nesting depth');

        $this->canonicalizer()->canonicalize([
            'delivery' => [
                'level_1' => [
                    'level_2' => [
                        'level_3' => [
                            'level_4' => [
                                'level_5' => [
                                    'level_6' => [
                                        'level_7' => 'too deep',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_oversized_preserved_collection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too many collection items');

        $this->canonicalizer()->canonicalize([
            'delivery' => [
                'provider_attempts' => range(
                    1,
                    ScheduledMessageMetaCanonicalizer::MAX_LIST_ITEMS + 1,
                ),
            ],
        ]);
    }

    private function canonicalizer(): ScheduledMessageMetaCanonicalizer
    {
        return app(ScheduledMessageMetaCanonicalizer::class);
    }
}