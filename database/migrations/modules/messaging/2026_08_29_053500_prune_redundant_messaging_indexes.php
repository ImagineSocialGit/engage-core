<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->dropIndex('scheduled_messages_queue_index');
            $table->dropIndex('scheduled_messages_status_index');
            $table->dropIndex('scheduled_messages_channel_index');
        });

        Schema::table('scheduled_message_delivery_attempts', function (Blueprint $table): void {
            $table->dropIndex('scheduled_message_delivery_attempts_status_index');
        });

        Schema::table('scheduled_message_outbox_events', function (Blueprint $table): void {
            $table->dropIndex('scheduled_message_outbox_events_status_index');
        });

        Schema::table('contact_permission_invitations', function (Blueprint $table): void {
            $table->dropIndex('contact_permission_invitations_channel_index');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->index('queue', 'scheduled_messages_queue_index');
            $table->index('status', 'scheduled_messages_status_index');
            $table->index('channel', 'scheduled_messages_channel_index');
        });

        Schema::table('scheduled_message_delivery_attempts', function (Blueprint $table): void {
            $table->index('status', 'scheduled_message_delivery_attempts_status_index');
        });

        Schema::table('scheduled_message_outbox_events', function (Blueprint $table): void {
            $table->index('status', 'scheduled_message_outbox_events_status_index');
        });

        Schema::table('contact_permission_invitations', function (Blueprint $table): void {
            $table->index('channel', 'contact_permission_invitations_channel_index');
        });
    }
};