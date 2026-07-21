<?php

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookable_service_hosts', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(BookableService::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(SchedulingHost::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('capacity_override')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(
                ['bookable_service_id', 'scheduling_host_id'],
                'bookable_service_hosts_service_host_unique',
            );

            $table->index(
                ['scheduling_host_id', 'is_active', 'sort_order'],
                'bookable_service_hosts_host_active_sort_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookable_service_hosts');
    }
};