<?php

use App\Modules\Core\Models\Contact;
use App\Modules\PetServices\Models\Pet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('species', 80)->index();
            $table->string('breed', 160)->nullable();
            $table->string('sex', 40)->nullable();
            $table->date('birth_date')->nullable();
            $table->boolean('birth_date_is_estimated')->default(false);
            $table->boolean('is_spayed_neutered')->nullable();
            $table->string('status', 40)->default('active')->index();
            $table->string('source', 40)->default('manual')->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['species', 'status'],
                'pets_species_status_index',
            );
        });

        Schema::create('pet_contact_links', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(Pet::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(Contact::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('role', 40)->default('owner');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['pet_id', 'contact_id', 'role'],
                'pet_contact_links_identity_unique',
            );
            $table->index(
                ['contact_id', 'is_active'],
                'pet_contact_links_contact_active_index',
            );
            $table->index(
                ['pet_id', 'is_active', 'is_primary'],
                'pet_contact_links_pet_active_primary_index',
            );
        });

        Schema::create('pet_training_goals', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(Pet::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('goal');
            $table->string('status', 40)->default('active');
            $table->string('priority', 40)->default('normal');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('source', 40)->default('manual');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['pet_id', 'status', 'priority'],
                'pet_training_goals_pet_status_priority_index',
            );
        });

        Schema::create('pet_behavior_notes', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(Pet::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('category', 80)->nullable();
            $table->string('severity', 40)->nullable();
            $table->text('note');
            $table->dateTime('observed_at')->nullable();
            $table->string('source', 40)->default('manual');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['pet_id', 'observed_at'],
                'pet_behavior_notes_pet_observed_index',
            );
        });

        Schema::create('pet_vaccinations', function (Blueprint $table): void {
            $table->id();

            $table->foreignIdFor(Pet::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('vaccination_key', 120);
            $table->string('name');
            $table->date('administered_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('verification_status', 40)->default('unverified');
            $table->dateTime('verified_at')->nullable();
            $table->string('source', 40)->default('manual');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['pet_id', 'vaccination_key', 'expires_on'],
                'pet_vaccinations_pet_key_expiry_index',
            );
            $table->index(
                ['pet_id', 'verification_status'],
                'pet_vaccinations_pet_verification_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_vaccinations');
        Schema::dropIfExists('pet_behavior_notes');
        Schema::dropIfExists('pet_training_goals');
        Schema::dropIfExists('pet_contact_links');
        Schema::dropIfExists('pets');
    }
};