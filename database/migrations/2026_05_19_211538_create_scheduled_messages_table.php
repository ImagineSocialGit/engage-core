<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();

            $table->morphs('recipient');

            $table->nullableMorphs('context');

            $table->string('channel')->index();
            $table->string('message_type')->index();

            $table->string('purpose')->index();
            $table->string('scope')->index();

            $table->string('payload_class');
            $table->json('payload');

            $table->timestamp('send_at')->index();

            $table->string('status')
                ->default('pending')
                ->index();

            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('skipped_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();

            $table->string('dedupe_key')
                ->nullable()
                ->unique();

            $table->text('failure_reason')->nullable();
            $table->text('skip_reason')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index([
                'channel',
                'purpose',
                'scope',
            ], 'scheduled_messages_channel_purpose_scope_index');

            $table->index([
                'context_type',
                'context_id',
                'channel',
                'message_type',
            ], 'scheduled_messages_context_channel_type_index');

            $table->index([
                'status',
                'send_at',
            ], 'scheduled_messages_status_send_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};