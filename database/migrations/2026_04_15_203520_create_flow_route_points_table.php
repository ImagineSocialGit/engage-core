<?php

use App\Models\FlowRoute;
use App\Models\Point;
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

            $table->unsignedSmallInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();

            $table->integer('due_offset_days')->nullable();

            $table->string('assignment_strategy')->nullable();
            $table->nullableMorphs('assigned_to');

            $table->json('cancel_conditions')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['flow_route_id', 'point_id']);
            $table->index(['flow_route_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_route_points');
    }
};