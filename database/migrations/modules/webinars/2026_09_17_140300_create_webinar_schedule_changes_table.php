<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_schedule_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_id');
            $table->timestamp('previous_starts_at');
            $table->timestamp('current_starts_at');
            $table->string('previous_timezone', 100);
            $table->string('current_timezone', 100);
            $table->string('status', 20)->default('pending');
            $table->json('channels')->nullable();
            $table->unsignedBigInteger('last_registration_id')->default(0);
            $table->unsignedInteger('messages_queued')->default(0);
            $table->unsignedInteger('messages_cancelled')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('webinar_id', 'wsc_webinar_fk')
                ->references('id')->on('webinars')->cascadeOnDelete();
            $table->index(['webinar_id', 'status', 'id'], 'wsc_webinar_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_schedule_changes');
    }
};