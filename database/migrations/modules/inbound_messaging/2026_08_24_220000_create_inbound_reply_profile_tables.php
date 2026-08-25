<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_reply_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 96)->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('source')->default('database')->index();
            $table->string('source_config_path')->nullable();
            $table->string('source_version')->nullable();
            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('inbound_reply_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inbound_reply_profile_id')
                ->constrained('inbound_reply_profiles')
                ->cascadeOnDelete();
            $table->string('key', 96);
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['inbound_reply_profile_id', 'key'],
                'reply_intent_profile_key_unique',
            );
            $table->index(
                ['inbound_reply_profile_id', 'is_active', 'sort_order'],
                'reply_intent_profile_active_order_index',
            );
        });

        Schema::create('inbound_reply_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inbound_reply_intent_id')
                ->constrained('inbound_reply_intents')
                ->cascadeOnDelete();
            $table->string('match_type', 20);
            $table->string('value');
            $table->string('normalized_value');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['inbound_reply_intent_id', 'match_type', 'normalized_value'],
                'reply_rule_intent_type_value_unique',
            );
            $table->index(
                ['inbound_reply_intent_id', 'is_active', 'sort_order'],
                'reply_rule_intent_active_order_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_reply_rules');
        Schema::dropIfExists('inbound_reply_intents');
        Schema::dropIfExists('inbound_reply_profiles');
    }
};