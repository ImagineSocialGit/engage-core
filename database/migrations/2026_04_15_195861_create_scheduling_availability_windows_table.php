<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduling_availability_windows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bookable_service_id')
                ->nullable()
                ->constrained('bookable_services')
                ->nullOnDelete();

            $table->nullableMorphs('owner');

            $table->string('timezone')->default('UTC')->index();

            $table->unsignedTinyInteger('weekday')->nullable()->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->unsignedInteger('capacity')->nullable();
            $table->string('rrule')->nullable();

            $table->boolean('is_available')->default(true)->index();

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index([
                'bookable_service_id',
                'is_available',
                'weekday',
            ], 'scheduling_availability_service_weekday_index');

            $table->index([
                'owner_type',
                'owner_id',
                'is_available',
            ], 'scheduling_availability_owner_available_index');

            $table->index([
                'provider',
                'external_id',
            ], 'scheduling_availability_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduling_availability_windows');
    }
};
