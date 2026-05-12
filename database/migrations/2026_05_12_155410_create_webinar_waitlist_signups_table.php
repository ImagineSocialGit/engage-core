<?php

use App\Models\WebinarSeries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_waitlist_signups', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(WebinarSeries::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();

            $table->timestamp('email_consent_at')->nullable();
            $table->timestamp('sms_consent_at')->nullable();

            $table->string('source_page')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['webinar_series_id', 'email']);
            $table->index('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_waitlist_signups');
    }
};