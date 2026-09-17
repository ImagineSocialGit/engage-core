<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_message_bulk_edits', function (Blueprint $table): void {
            $table->id();
            $table->string('source_scope', 32);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('message_template_version_id');
            $table->unsignedBigInteger('maximum_scheduled_message_id');
            $table->string('channel', 16);
            $table->unsignedInteger('matching_count_at_creation');
            $table->json('override_payload');
            $table->string('action', 20);
            $table->foreignId('actor_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('reason', 1000)->nullable();
            $table->timestamp('created_at');

            $table->foreign('message_template_version_id', 'sm_bulk_template_fk')
                ->references('id')->on('message_template_versions')->restrictOnDelete();
            $table->foreign('actor_id', 'sm_bulk_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index([
                'message_template_version_id',
                'maximum_scheduled_message_id',
            ], 'sm_bulk_version_cutoff_idx');
            $table->index([
                'source_scope',
                'source_id',
                'message_template_version_id',
                'id',
            ], 'sm_bulk_scope_latest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_message_bulk_edits');
    }
};