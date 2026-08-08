<?php

use App\Modules\FlowRoutes\Models\FlowRoute;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_route_trigger_bindings', function (Blueprint $table) {
            $table->id();

            $table->string('trigger_type')->index();
            $table->string('trigger_key')->nullable()->index();

            $table->foreignIdFor(FlowRoute::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->nullableMorphs('context', 'frtb_context_morph_idx');

            $table->boolean('is_active')->default(true)->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['trigger_type', 'trigger_key', 'is_active'], 'frtb_trigger_active_idx');
            $table->index(['trigger_type', 'trigger_key', 'context_type', 'context_id', 'is_active'], 'frtb_context_active_idx');
            $table->index(['flow_route_id', 'is_active'], 'frtb_route_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_route_trigger_bindings');
    }
};

