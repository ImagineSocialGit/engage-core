<?php

use App\Modules\Events\Enums\EventAttendanceMode;
use App\Modules\Events\Enums\EventStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();

            $table->string('type_key', 80)->index();
            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status', 32)
                ->default(EventStatus::Draft->value)
                ->index();

            $table->string('attendance_mode', 32)
                ->default(EventAttendanceMode::Physical->value)
                ->index();

            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->string('timezone', 100)->default('UTC')->index();
            $table->timestamp('announcement_at')->nullable()->index();

            /*
             * The foreign key is added by the external-references migration
             * after event_external_references exists.
             */
            $table->unsignedBigInteger('primary_external_reference_id')
                ->nullable()
                ->index();

            $table->string('venue_name')->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 120)->nullable()->index();
            $table->string('region', 120)->nullable()->index();
            $table->string('postal_code', 32)->nullable();
            $table->string('country', 2)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['status', 'starts_at'],
                'events_status_starts_at_index',
            );

            $table->index(
                ['status', 'announcement_at'],
                'events_status_announcement_at_index',
            );

            $table->index(
                ['type_key', 'status', 'starts_at'],
                'events_type_status_starts_at_index',
            );

            $table->index(
                ['country', 'region', 'city'],
                'events_country_region_city_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};