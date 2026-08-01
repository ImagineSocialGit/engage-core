<?php

namespace Tests\Feature\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScheduledMessageTerminalPersistenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_messages_keep_only_compact_delivery_identity_and_lifecycle_state(): void
    {
        foreach ([
            'status',
            'send_at',
            'provider_idempotency_key',
            'dedupe_key',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('scheduled_messages', $column),
                "Expected scheduled_messages.{$column} to exist.",
            );
        }

        foreach ([
            'sending_at',
            'last_attempted_at',
            'send_attempts',
            'provider',
            'provider_message_id',
            'sent_at',
            'skipped_at',
            'failed_at',
            'failure_reason',
            'skip_reason',
        ] as $column) {
            $this->assertFalse(
                Schema::hasColumn('scheduled_messages', $column),
                "Expected scheduled_messages.{$column} to be removed.",
            );
        }
    }

    public function test_delivery_attempts_and_outbox_own_terminal_details(): void
    {
        foreach ([
            'attempt_number',
            'provider',
            'provider_message_id',
            'completed_at',
            'reason_code',
            'reason',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('scheduled_message_delivery_attempts', $column),
                "Expected scheduled_message_delivery_attempts.{$column} to exist.",
            );
        }

        foreach ([
            'delivery_attempt_id',
            'event_type',
            'occurred_at',
            'reason_code',
            'reason',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('scheduled_message_outbox_events', $column),
                "Expected scheduled_message_outbox_events.{$column} to exist.",
            );
        }
    }
}