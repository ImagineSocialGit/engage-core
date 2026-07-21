<?php

use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_lifecycle_events', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Appointment::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->uuid('event_id')->unique();
            $table->string('event_key')->index();

            $table->string('from_status')->nullable()->index();
            $table->string('to_status')->nullable()->index();

            $table->nullableMorphs('actor');

            $table->string('source')->default('system')->index();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->index();

            $table->timestamps();

            $table->index(
                ['appointment_id', 'occurred_at'],
                'appointment_lifecycle_appointment_occurred_index',
            );

            $table->index(
                ['appointment_id', 'event_key'],
                'appointment_lifecycle_appointment_event_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_lifecycle_events');
    }
};