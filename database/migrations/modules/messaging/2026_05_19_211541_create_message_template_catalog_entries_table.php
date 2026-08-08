<?php

use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_template_catalog_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(MessageTemplatePreset::class)
                ->constrained(
                    table: 'message_template_presets',
                    indexName: 'mtce_preset_fk'
                )
                ->cascadeOnDelete();

            $table->string('channel', 32)->index();
            $table->string('purpose', 32)->index();
            $table->string('scope', 96)->index();

            $table->string('module_key', 96)->index();
            $table->string('module_label', 128);
            $table->string('surface', 96)->nullable()->index();

            $table->string('group_key', 191)->index();
            $table->string('group_label', 191);

            $table->string('item_key', 191)->index();
            $table->string('item_label', 191);
            $table->integer('item_order')->default(0)->index();

            $table->string('usage_type', 96)->index();

            $table->string('source', 64)->nullable()->index();
            $table->string('source_config_path', 191)->nullable()->index();

            $table->string('context_type', 191)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique([
                'message_template_preset_id',
                'item_key',
            ], 'mtce_preset_item_unique');

            $table->index([
                'channel',
                'purpose',
                'module_key',
                'group_key',
                'item_order',
            ], 'mtce_catalog_browse_idx');

            $table->index([
                'context_type',
                'context_id',
            ], 'mtce_context_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_template_catalog_entries');
    }
};
