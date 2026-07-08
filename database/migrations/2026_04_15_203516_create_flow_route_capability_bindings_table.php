<?php

use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_route_capability_bindings', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(FlowRouteCapability::class)
                ->constrained(
                    table: 'flow_route_capabilities',
                    indexName: 'frcb_capability_fk',
                )
                ->cascadeOnDelete();

            $table->nullableMorphs('context', 'frcb_context_morph_idx');
            $table->nullableMorphs('owner', 'frcb_owner_morph_idx');

            $table->string('module_key', 120)->nullable()->index();
            $table->string('visibility', 40)->default('operator')->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->text('help_text')->nullable();

            $table->json('defaults')->nullable();
            $table->json('constraints')->nullable();
            $table->json('input_overrides')->nullable();
            $table->json('output_overrides')->nullable();

            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['flow_route_capability_id', 'is_enabled'], 'frcb_capability_enabled_idx');
            $table->index(['context_type', 'context_id', 'is_enabled'], 'frcb_context_enabled_idx');
            $table->index(['owner_type', 'owner_id', 'is_enabled'], 'frcb_owner_enabled_idx');
            $table->index(['module_key', 'visibility', 'is_enabled'], 'frcb_module_visibility_enabled_idx');
            $table->index(['visibility', 'is_enabled', 'sort_order'], 'frcb_visibility_enabled_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_route_capability_bindings');
    }
};
