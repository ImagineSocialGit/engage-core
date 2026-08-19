<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Location\Models\Location;
use App\Modules\Location\Models\LocationArea;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_area_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(LocationArea::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(Location::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->nullableMorphs('subject');

            $table->string('role')->default('member')->index();
            $table->string('status')->default('active')->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();

            $table->string('source')->default('manual')->index();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['location_area_id', 'status'], 'location_area_assignments_area_status_index');
            $table->index(['location_id', 'status'], 'location_area_assignments_location_status_index');
            $table->index(['contact_id', 'status'], 'location_area_assignments_contact_status_index');
            $table->index(['role', 'status'], 'location_area_assignments_role_status_index');
            $table->unique(
                ['location_area_id', 'subject_type', 'subject_id', 'role'],
                'loc_area_subject_role_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_area_assignments');
    }
};