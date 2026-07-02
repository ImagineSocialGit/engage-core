<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Location\Models\Location;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
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
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(Location::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->nullableMorphs('primary_attendee');

            $table->foreignIdFor(Appointment::class, 'rescheduled_from_id')
                ->nullable()
                ->constrained('appointments')
                ->nullOnDelete();

            $table->string('status')->default('scheduled')->index();

            $table->string('title')->nullable();
            $table->text('description')->nullable();

            $table->string('location_type')->nullable()->index();
            $table->json('location_details')->nullable();

            $table->string('timezone')->default('UTC')->index();

            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();

            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('no_show_at')->nullable()->index();
            $table->timestamp('canceled_at')->nullable()->index();
            $table->text('cancellation_reason')->nullable();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('external_url')->nullable();

            $table->nullableMorphs('created_by');

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at'], 'appointments_status_starts_at_index');
            $table->index(['contact_id', 'status', 'starts_at'], 'appointments_contact_status_starts_at_index');
            $table->index(['location_id', 'status', 'starts_at'], 'appointments_location_status_starts_at_index');
            $table->index(['bookable_service_id', 'status', 'starts_at'], 'appointments_service_status_starts_at_index');
            $table->index(['provider', 'external_id'], 'appointments_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
