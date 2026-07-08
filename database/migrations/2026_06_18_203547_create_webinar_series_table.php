<?php

use App\Modules\Webinars\Models\WebinarScheduleProfile;
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
        Schema::create('webinar_series', function (Blueprint $table) {
            $table->id();

            $table->string('slug')->unique();
            $table->string('title');

            $table->string('status')->default('active')->index();

            $table->foreignIdFor(WebinarScheduleProfile::class)
                ->nullable()
                ->constrained('webinar_schedule_profiles')
                ->nullOnDelete();

            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webinar_series');
    }
};

