<?php

namespace Tests\Feature\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessagingIndexShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_redundant_single_column_indexes_are_pruned_while_covering_indexes_remain(): void
    {
        $indexes = $this->indexNames([
            'scheduled_messages',
            'scheduled_message_delivery_attempts',
            'scheduled_message_outbox_events',
            'contact_permission_invitations',
        ]);

        foreach ([
            'scheduled_messages_queue_index',
            'scheduled_messages_status_index',
            'scheduled_messages_channel_index',
            'scheduled_message_delivery_attempts_status_index',
            'scheduled_message_outbox_events_status_index',
            'contact_permission_invitations_channel_index',
        ] as $index) {
            $this->assertNotContains($index, $indexes);
        }

        foreach ([
            'scheduled_messages_queue_status_send_at_index',
            'scheduled_messages_status_send_at_index',
            'scheduled_messages_channel_purpose_scope_index',
            'scheduled_messages_context_type_context_id_index',
            'scheduled_message_delivery_attempt_stale_index',
            'scheduled_message_outbox_events_pending_index',
            'scheduled_message_outbox_events_stale_claim_index',
            'contact_permission_invitations_channel_source_status_index',
        ] as $index) {
            $this->assertContains($index, $indexes);
        }
    }

    /**
     * @param array<int, string> $tables
     * @return array<int, string>
     */
    private function indexNames(array $tables): array
    {
        $database = DB::connection()->getDatabaseName();
        $placeholders = implode(',', array_fill(0, count($tables), '?'));

        $rows = DB::select(
            "SELECT DISTINCT INDEX_NAME AS index_name
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME IN ({$placeholders})",
            [$database, ...$tables],
        );

        return collect($rows)
            ->map(fn (object $row): string => (string) $row->index_name)
            ->values()
            ->all();
    }
}