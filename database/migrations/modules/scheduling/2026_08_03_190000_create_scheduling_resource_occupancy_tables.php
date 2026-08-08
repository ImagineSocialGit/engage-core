<?php

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Models\SchedulingResource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_resources', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('status', 40)->default('active')->index();
            $table->string('source', 40)->default('manual')->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['status', 'sort_order'],
                'scheduling_resources_status_sort_index',
            );
        });

        Schema::create('scheduling_host_resources', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(SchedulingHost::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(SchedulingResource::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('capacity');
            $table->boolean('is_active')->default(true)->index();
            $table->string('source', 40)->default('manual')->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();

            $table->unique(
                ['scheduling_host_id', 'scheduling_resource_id'],
                'scheduling_host_resources_host_resource_unique',
            );

            $table->index(
                ['scheduling_resource_id', 'is_active'],
                'scheduling_host_resources_resource_active_index',
            );
        });

        Schema::create('bookable_service_resource_requirements', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(BookableService::class)
                ->constrained(
                    table: null,
                    column: null,
                    indexName: 'bsrr_service_fk',
                )
                ->cascadeOnDelete();

            $table->foreignIdFor(SchedulingResource::class)
                ->constrained(
                    table: null,
                    column: null,
                    indexName: 'bsrr_resource_fk',
                )
                ->cascadeOnDelete();

            $table->unsignedInteger('quantity');
            $table->boolean('is_active')->default(true)->index();
            $table->string('source', 40)->default('manual')->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();

            $table->unique(
                ['bookable_service_id', 'scheduling_resource_id'],
                'bookable_service_resource_requirements_service_resource_unique',
            );

            $table->index(
                ['scheduling_resource_id', 'is_active'],
                'bookable_service_resource_requirements_resource_active_index',
            );
        });

        Schema::create('scheduling_resource_occupancies', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(SchedulingResource::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(SchedulingHost::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(Appointment::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(BookingHold::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('quantity');
            $table->dateTime('occupancy_starts_at');
            $table->dateTime('occupancy_ends_at');
            $table->timestamps();

            $table->unique(
                ['appointment_id', 'scheduling_resource_id'],
                'scheduling_resource_occupancies_appointment_resource_unique',
            );

            $table->unique(
                ['booking_hold_id', 'scheduling_resource_id'],
                'scheduling_resource_occupancies_hold_resource_unique',
            );

            $table->index(
                [
                    'scheduling_host_id',
                    'scheduling_resource_id',
                    'occupancy_starts_at',
                    'occupancy_ends_at',
                ],
                'scheduling_resource_occupancies_host_resource_time_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_resource_occupancies');
        Schema::dropIfExists('bookable_service_resource_requirements');
        Schema::dropIfExists('scheduling_host_resources');
        Schema::dropIfExists('scheduling_resources');
    }
};