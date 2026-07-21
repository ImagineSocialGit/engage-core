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
        Schema::create('scheduling_availability_windows', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(BookableService::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(SchedulingHost::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('window_type')->default('weekly')->index();
            $table->string('timezone')->default('UTC')->index();

            $table->unsignedTinyInteger('weekday')->nullable()->index();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();

            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('is_available')->default(true)->index();

            $table->string('source')->default('manual')->index();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index([
                'bookable_service_id',
                'scheduling_host_id',
                'window_type',
                'is_available',
            ], 'scheduling_availability_scope_type_index');

            $table->index([
                'window_type',
                'weekday',
                'is_available',
            ], 'scheduling_availability_weekly_lookup_index');

            $table->index([
                'window_type',
                'starts_at',
                'ends_at',
                'is_available',
            ], 'scheduling_availability_absolute_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_availability_windows');
    }
};