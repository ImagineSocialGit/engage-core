<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_touch_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')
                ->constrained('campaigns')
                ->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('name')->nullable();
            $table->string('audience_type', 32)->default('contact_status');
            $table->string('audience_key', 120)->nullable();
            $table->string('recurrence', 32)->default('annual');
            $table->unsignedSmallInteger('repeat_years')->default(10);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['campaign_id', 'key'],
                'campaign_touch_program_key_unique',
            );
            $table->index(
                ['campaign_id', 'is_active'],
                'campaign_touch_program_active_idx',
            );
            $table->index(
                ['audience_type', 'audience_key'],
                'campaign_touch_program_audience_idx',
            );
        });

        Schema::create('campaign_touch_dates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_touch_program_id')
                ->constrained('campaign_touch_programs')
                ->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('name')->nullable();
            $table->string('source_type', 32);
            $table->string('source_key', 191)->nullable();
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedTinyInteger('day')->nullable();
            $table->smallInteger('offset_days')->default(0);
            $table->time('send_time')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['campaign_touch_program_id', 'key'],
                'campaign_touch_date_key_unique',
            );
            $table->index(
                ['campaign_touch_program_id', 'is_active', 'sort_order'],
                'campaign_touch_date_active_idx',
            );
            $table->index(
                ['source_type', 'source_key'],
                'campaign_touch_date_source_idx',
            );
        });

        Schema::create('campaign_touch_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_touch_date_id')
                ->constrained('campaign_touch_dates')
                ->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('name')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('channel', 32)->index();
            $table->string('purpose', 32)->index();
            $table->string('scope', 120)->index();

            // Messaging owns reusable copy. This is a logical reference only,
            // matching Campaigns' existing cross-module MessageChain bridge.
            $table->unsignedBigInteger('message_template_preset_id')
                ->nullable()
                ->index();

            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['campaign_touch_date_id', 'key'],
                'campaign_touch_variant_key_unique',
            );
            $table->index(
                ['campaign_touch_date_id', 'is_active', 'sort_order'],
                'campaign_touch_variant_active_idx',
            );
            $table->index(
                ['campaign_touch_date_id', 'channel', 'purpose', 'scope'],
                'campaign_touch_variant_context_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_touch_variants');
        Schema::dropIfExists('campaign_touch_dates');
        Schema::dropIfExists('campaign_touch_programs');
    }
};