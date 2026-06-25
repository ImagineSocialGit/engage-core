<?php

use App\Models\Contact;
use App\Models\Webinar;
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
        Schema::create('webinar_registrations', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Contact::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Webinar::class)->constrained()->cascadeOnDelete();

            $table->string('join_token')->unique();

            $table->string('webinar_slug')->default('default')->index();
            $table->string('status')->default('pending')->index();
            $table->string('source')->default('webinar_subdomain')->index();

            $table->json('meta')->nullable();

            $table->timestamp('registered_at')->nullable();
            $table->timestamp('attended_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable()->index();
            
            $table->timestamps();

            $table->unique(
                ['webinar_id', 'contact_id'],
                'webinar_registrations_webinar_contact_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webinar_registrations');
    }
};