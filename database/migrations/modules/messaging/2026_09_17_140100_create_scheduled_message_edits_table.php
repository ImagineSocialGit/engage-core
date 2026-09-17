<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_message_edits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduled_message_id');
            $table->foreignId('message_template_version_id')->nullable();
            $table->foreignId('actor_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->json('override_payload');
            $table->string('action', 20);
            $table->string('reason', 1000)->nullable();
            $table->timestamp('created_at');

            $table->foreign('scheduled_message_id', 'sm_edits_message_fk')
                ->references('id')->on('scheduled_messages')->cascadeOnDelete();
            $table->foreign('message_template_version_id', 'sm_edits_template_fk')
                ->references('id')->on('message_template_versions')->nullOnDelete();
            $table->foreign('actor_id', 'sm_edits_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['scheduled_message_id', 'id'], 'sm_edits_message_latest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_message_edits');
    }
};