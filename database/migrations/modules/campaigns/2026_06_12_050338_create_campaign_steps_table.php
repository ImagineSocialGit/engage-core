<?php

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Campaign::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('step_number');

            $table->string('name')->nullable();
            $table->string('dispatch_key', 191);

            $table->string('channel', 32)->index();
            $table->string('purpose', 32)->index();
            $table->string('scope', 120)->index();
            $table->string('variant_strategy', 32)->default('first_available');

            $table->boolean('is_active')->default(true)->index();

            $table->json('criteria')->nullable();

            $table->string('source_version')->nullable();

            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['campaign_id', 'step_number']);

            $table->index(['campaign_id', 'is_active', 'step_number']);
            $table->index(['campaign_id', 'channel', 'purpose', 'scope'], 'campaign_steps_message_context_index');
            $table->index(['campaign_id', 'variant_strategy'], 'campaign_steps_variant_strategy_index');
            $table->index(['dispatch_key']);
            $table->index(['campaign_id', 'source_version']);
        });

        Schema::create('campaign_step_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(CampaignStep::class)
                ->constrained('campaign_steps')
                ->cascadeOnDelete();

            $table->string('key', 120);
            $table->string('name')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->string('dispatch_key', 191);
            $table->string('channel', 32)->index();
            $table->string('purpose', 32)->index();
            $table->string('scope', 120)->index();

            $table->boolean('is_active')->default(true)->index();
            $table->json('criteria')->nullable();
            $table->json('dependency_rules')->nullable();

            $table->string('source_config_path')->nullable();
            $table->string('source_version')->nullable();

            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['campaign_step_id', 'key']);
            $table->index(['campaign_step_id', 'is_active', 'sort_order'], 'campaign_step_variants_active_order_index');
            $table->index(['campaign_step_id', 'channel', 'purpose', 'scope'], 'campaign_step_variants_message_context_index');
            $table->index(['dispatch_key']);
            $table->index(['campaign_step_id', 'source_version'], 'campaign_step_variants_source_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_step_variants');
        Schema::dropIfExists('campaign_steps');
    }
};
