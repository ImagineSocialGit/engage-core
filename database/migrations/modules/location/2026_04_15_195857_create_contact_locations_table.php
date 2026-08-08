<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Location\Models\Location;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_locations', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(Location::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->nullableMorphs('subject');

            $table->string('type')->default('home')->index();
            $table->string('label')->nullable();
            $table->string('status')->default('active')->index();
            $table->boolean('is_primary')->default(false)->index();

            $table->timestamp('verified_at')->nullable()->index();
            $table->timestamp('valid_from')->nullable()->index();
            $table->timestamp('valid_until')->nullable()->index();

            $table->string('source')->default('manual')->index();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['contact_id', 'status', 'type'], 'contact_locations_contact_status_type_index');
            $table->index(['location_id', 'status'], 'contact_locations_location_status_index');
            $table->index(['contact_id', 'is_primary'], 'contact_locations_contact_primary_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_locations');
    }
};
