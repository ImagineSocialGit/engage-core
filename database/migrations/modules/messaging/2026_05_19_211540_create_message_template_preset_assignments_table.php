<?php

use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_template_preset_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(MessageTemplatePreset::class)
                ->constrained(
                    table: 'message_template_presets',
                    indexName: 'mtpa_preset_fk'
                )
                ->cascadeOnDelete();

            $table->string('channel', 32)->index();
            $table->string('purpose', 32)->index();
            $table->string('scope', 96)->index();
            $table->string('surface', 96)->nullable()->index();
            $table->string('message_type', 128)->nullable()->index();
            $table->string('definition_key', 128)->nullable()->index('mtpa_definition_key_idx');

            $table->string('campaign_key', 128)->nullable()->index();
            $table->unsignedInteger('campaign_step')->nullable()->index();
            $table->string('campaign_step_variant_key', 128)->nullable();
            $table->string('source_config_path', 191)->nullable();

            $table->string('context_type', 191)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index([
                'context_type',
                'context_id',
            ], 'mtpa_context_idx');

            $table->index([
                'channel',
                'purpose',
                'scope',
                'message_type',
                'definition_key',
            ], 'mtpa_msg_ctx_idx');

            $table->index([
                'campaign_key',
                'campaign_step',
                'channel',
                'purpose',
                'scope',
            ], 'mtpa_campaign_ctx_idx');

            $table->index([
                'campaign_key',
                'campaign_step',
                'campaign_step_variant_key',
                'channel',
                'purpose',
                'scope',
            ], 'mtpa_campaign_variant_ctx_idx');

            $table->index('campaign_step_variant_key', 'mtpa_variant_key_idx');
            $table->index('source_config_path', 'mtpa_source_path_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_template_preset_assignments');
    }
};