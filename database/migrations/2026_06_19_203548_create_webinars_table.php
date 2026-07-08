<?php

use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webinars', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(WebinarSeries::class)->nullable()->constrained('webinar_series')->nullOnDelete();
            $table->foreignIdFor(WebinarScheduleProfile::class)
                ->nullable()
                ->constrained('webinar_schedule_profiles')
                ->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();

            $table->string('platform')->default('zoom');
            $table->string('external_id')->nullable()->index();
            $table->string('host_account_key')->nullable()->index();

            $table->string('join_url')->nullable();
            $table->string('registration_url')->nullable();
            $table->string('playback_token')->unique()->nullable();
            $table->string('playback_url')->nullable();
            $table->string('playback_passcode')->nullable();

            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone')->default('America/Chicago');

            $table->text('description')->nullable();

            $table->json('meta')->nullable();
            $table->json('provider_settings')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webinars');
    }
};

