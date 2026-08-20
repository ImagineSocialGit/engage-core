<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_message_cta_engagements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('scheduled_message_id');
            $table->string('cta_key', 96);
            $table->string('classification', 32);
            $table->unsignedInteger('occurrence_count')->default(0);
            $table->timestamp('first_occurred_at');
            $table->timestamp('last_occurred_at');

            $table->unique(
                ['scheduled_message_id', 'cta_key', 'classification'],
                'sm_cta_engagement_identity_unique',
            );

            $table->foreign(
                'scheduled_message_id',
                'sm_cta_engagement_message_fk',
            )
                ->references('id')
                ->on('scheduled_messages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_message_cta_engagements');
    }
};