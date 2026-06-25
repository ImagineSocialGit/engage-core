<?php

use App\Models\Contact;
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

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(WebinarSeries::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamp('notified_at')->nullable()->index();

            $table->string('source_page')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['webinar_series_id', 'notified_at']);
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_waitlist_signups');
    }
};