<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('related_contact_id')
                ->nullable()
                ->after('sender_id');

            $table->string('inbox_status', 32)
                ->default('new')
                ->after('processed_at');

            $table->timestamp('reviewed_at')
                ->nullable()
                ->after('inbox_status');

            $table->timestamp('completed_at')
                ->nullable()
                ->after('reviewed_at');

            $table->index(
                'related_contact_id',
                'inbound_messages_related_contact_idx',
            );

            $table->index(
                ['inbox_status', 'received_at'],
                'inbound_messages_inbox_status_received_idx',
            );
        });

        /*
         * Existing history predates the Inbox. Do not turn every historical
         * inbound message into new work on deployment.
         */
        DB::table('inbound_messages')->update([
            'inbox_status' => 'done',
            'reviewed_at' => null,
            'completed_at' => DB::raw(
                'COALESCE(processed_at, received_at, created_at)',
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('inbound_messages', function (Blueprint $table): void {
            $table->dropIndex('inbound_messages_inbox_status_received_idx');
            $table->dropIndex('inbound_messages_related_contact_idx');

            $table->dropColumn([
                'related_contact_id',
                'inbox_status',
                'reviewed_at',
                'completed_at',
            ]);
        });
    }
};