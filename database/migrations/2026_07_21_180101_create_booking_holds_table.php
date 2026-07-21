<?php

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableSlotOffer;
use App\Modules\Scheduling\Models\SchedulingHost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_holds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('hold_id')->unique();
            $table->foreignIdFor(BookableSlotOffer::class)
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(BookableService::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(SchedulingHost::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(Appointment::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('idempotency_key', 191)->unique();
            $table->string('status', 40)->default('active');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('occupancy_starts_at');
            $table->dateTime('occupancy_ends_at');
            $table->unsignedInteger('capacity');
            $table->dateTime('held_at');
            $table->dateTime('expires_at');
            $table->dateTime('released_at')->nullable();
            $table->dateTime('converted_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at'], 'booking_holds_status_expiry_index');
            $table->index(
                ['scheduling_host_id', 'occupancy_starts_at', 'occupancy_ends_at'],
                'booking_holds_host_occupancy_index',
            );
            $table->index(
                ['bookable_service_id', 'scheduling_host_id', 'occupancy_starts_at', 'occupancy_ends_at'],
                'booking_holds_service_host_occupancy_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_holds');
    }
};