<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_workflow_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(ContactStatus::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->nullableMorphs('assigned_to');

            $table->timestamp('last_status_changed_at')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique('contact_id');
            $table->index(['contact_status_id', 'last_status_changed_at'], 'workflow_profile_status_changed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_workflow_profiles');
    }
};