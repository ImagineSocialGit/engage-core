<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_opportunities', function (Blueprint $table) {
            $table->id();

            $table->string('action_key', 191)->index();
            $table->string('fingerprint', 64);

            $table->string('capability_key', 191)->nullable()->index();

            $table->string('status', 40)->default('observing')->index();

            $table->unsignedInteger('occurrence_count')->default(0);
            $table->unsignedInteger('distinct_subject_count')->default(0);
            $table->unsignedInteger('distinct_actor_count')->default(0);

            $table->timestamp('first_occurred_at')->nullable()->index();
            $table->timestamp('last_occurred_at')->nullable()->index();

            $table->timestamp('eligible_at')->nullable()->index();
            $table->timestamp('suggested_at')->nullable()->index();
            $table->timestamp('dismissed_at')->nullable()->index();
            $table->timestamp('dismissed_until')->nullable()->index();
            $table->timestamp('converted_at')->nullable()->index();
            $table->timestamp('invalidated_at')->nullable()->index();

            $table->json('context')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(
                ['action_key', 'fingerprint'],
                'automation_opportunities_action_fingerprint_unique',
            );

            $table->index(
                ['status', 'last_occurred_at'],
                'ao_status_last_occurred_idx',
            );

            $table->index(
                ['status', 'eligible_at'],
                'ao_status_eligible_idx',
            );

            $table->index(
                ['capability_key', 'status'],
                'ao_capability_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_opportunities');
    }
};
