<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_behavior_occurrences', function (Blueprint $table) {
            $table->id();

            $table->string('action_key', 191)->index();

            $table->nullableMorphs('actor', 'abo_actor_morph_idx');
            $table->nullableMorphs('subject', 'abo_subject_morph_idx');

            $table->string('capability_key', 191)->nullable()->index();

            $table->string('fingerprint', 64);
            $table->json('fingerprint_parts');

            $table->json('context')->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('occurred_at')->index();

            $table->timestamps();

            $table->index(
                ['action_key', 'fingerprint', 'occurred_at'],
                'abo_action_fingerprint_occurred_idx',
            );

            $table->index(
                ['action_key', 'occurred_at'],
                'abo_action_occurred_idx',
            );

            $table->index(
                ['capability_key', 'occurred_at'],
                'abo_capability_occurred_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_behavior_occurrences');
    }
};
