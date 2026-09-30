<?php

namespace Tests\Feature\PetServices;

use App\Modules\Core\Models\Contact;
use App\Modules\PetServices\Models\Pet;
use App\Modules\PetServices\Models\PetBehaviorNote;
use App\Modules\PetServices\Models\PetContactLink;
use App\Modules\PetServices\Models\PetTrainingGoal;
use App\Modules\PetServices\Models\PetVaccination;
use App\Modules\PetServices\Providers\PetServicesModuleServiceProvider;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PetServicesFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function additionalTestMigrationModuleKeys(): array
    {
        return ['pet_services'];
    }

    public function test_pet_services_is_registered_as_an_optional_core_dependent_vertical(): void
    {
        config()->set('modules.enabled', []);

        $modules = app(ModuleManager::class);
        $migrations = app(ModuleMigrationRegistry::class);

        $this->assertTrue($modules->known('pet_services'));
        $this->assertFalse($modules->enabled('pet_services'));
        $this->assertSame(['core'], $modules->dependencies('pet_services'));
        $this->assertContains(
            PetServicesModuleServiceProvider::class,
            $modules->providers('pet_services'),
        );

        $this->assertTrue($migrations->hasModule('pet_services'));
        $this->assertSame(
            'database/migrations/verticals/pet-services',
            $migrations->requireModule('pet_services')->path,
        );
    }

    public function test_pet_domain_persists_ownership_training_behavior_and_vaccination_records(): void
    {
        $contact = Contact::factory()->create();

        $pet = Pet::query()->create([
            'name' => 'Ranger',
            'species' => 'dog',
            'breed' => 'Labrador Retriever',
            'sex' => 'male',
            'birth_date' => '2023-04-15',
            'birth_date_is_estimated' => true,
            'is_spayed_neutered' => true,
            'status' => Pet::STATUS_ACTIVE,
            'source' => 'manual',
        ]);

        $link = $pet->contactLinks()->create([
            'contact_id' => $contact->getKey(),
            'role' => PetContactLink::ROLE_OWNER,
            'is_primary' => true,
            'is_active' => true,
        ]);

        $goal = $pet->trainingGoals()->create([
            'goal' => 'Reliable recall around distractions',
            'status' => PetTrainingGoal::STATUS_ACTIVE,
            'priority' => PetTrainingGoal::PRIORITY_HIGH,
            'source' => 'manual',
        ]);

        $behavior = $pet->behaviorNotes()->create([
            'category' => 'reactivity',
            'severity' => 'watch',
            'note' => 'Barks at unfamiliar dogs when restrained.',
            'observed_at' => '2026-09-30 14:00:00',
            'source' => 'manual',
        ]);

        $vaccination = $pet->vaccinations()->create([
            'vaccination_key' => 'rabies',
            'name' => 'Rabies',
            'administered_on' => '2026-02-01',
            'expires_on' => '2027-02-01',
            'verification_status' => PetVaccination::VERIFICATION_VERIFIED,
            'verified_at' => '2026-09-30 14:05:00',
            'source' => 'manual',
        ]);

        $pet->refresh();

        $this->assertTrue($link->contact->is($contact));
        $this->assertTrue($link->pet->is($pet));
        $this->assertTrue($pet->contacts()->whereKey($contact->getKey())->exists());
        $this->assertTrue($pet->trainingGoals->contains($goal));
        $this->assertTrue($pet->behaviorNotes->contains($behavior));
        $this->assertTrue($pet->vaccinations->contains($vaccination));
        $this->assertTrue($pet->birth_date_is_estimated);
        $this->assertTrue($pet->is_spayed_neutered);
        $this->assertSame(
            PetVaccination::VERIFICATION_VERIFIED,
            $vaccination->verification_status,
        );
    }
}