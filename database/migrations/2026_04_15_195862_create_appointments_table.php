<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(BookableService::class)
                ->constrained()
                ->restrictOnDelete();

            $table->foreignIdFor(SchedulingHost::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->nullableMorphs('location_reference');
            $table->nullableMorphs('primary_attendee');
            $table->nullableMorphs('source_context');

            $table->foreignIdFor(Appointment::class, 'rescheduled_from_id')
                ->nullable()
                ->constrained('appointments')
                ->nullOnDelete();

            $table->unique(
                'rescheduled_from_id',
                'appointments_rescheduled_from_unique',
            );

            $table->string('status')->default('scheduled')->index();

            $table->string('title')->nullable();
            $table->text('description')->nullable();

            $table->string('location_type')->nullable()->index();
            $table->json('location_details')->nullable();

            $table->string('timezone')->default('UTC')->index();

            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->index();

            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('no_show_at')->nullable()->index();
            $table->timestamp('canceled_at')->nullable()->index();
            $table->text('cancellation_reason')->nullable();

            $table->string('source')->default('manual')->index();

            $table->nullableMorphs('created_by');

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['status', 'starts_at'],
                'appointments_status_starts_at_index',
            );

            $table->index(
                ['contact_id', 'status', 'starts_at'],
                'appointments_contact_status_starts_at_index',
            );

            $table->index(
                ['scheduling_host_id', 'status', 'starts_at'],
                'appointments_host_status_starts_at_index',
            );

            $table->index(
                ['bookable_service_id', 'status', 'starts_at'],
                'appointments_service_status_starts_at_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};