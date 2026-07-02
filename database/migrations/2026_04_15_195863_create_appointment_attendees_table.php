<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_attendees', function (Blueprint $table) {
            $table->id();

            $table->foreignId('appointment_id')
                ->constrained('appointments')
                ->cascadeOnDelete();

            $table->nullableMorphs('attendee');

            $table->foreignId('contact_id')
                ->nullable()
                ->constrained('contacts')
                ->nullOnDelete();

            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();

            $table->string('role')->nullable()->index();
            $table->string('status')->default('invited')->index();

            $table->timestamp('responded_at')->nullable()->index();
            $table->timestamp('joined_at')->nullable()->index();
            $table->timestamp('canceled_at')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['appointment_id', 'status'], 'appointment_attendees_appointment_status_index');
            $table->index(['contact_id', 'status'], 'appointment_attendees_contact_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_attendees');
    }
};
