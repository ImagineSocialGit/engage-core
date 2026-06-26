<?php

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\Point;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_route_points', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(FlowRoute::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(Point::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();

            $table->json('definition')->nullable();
            $table->json('settings')->nullable();
            $table->json('cancel_conditions')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['flow_route_id', 'sort_order']);
            $table->index(['flow_route_id', 'is_active', 'sort_order']);
            $table->index(['point_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_route_points');
    }
};