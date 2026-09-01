<?php

namespace Tests\Feature\Mortgage;

use App\Modules\Core\Models\Contact;
use App\Modules\Mortgage\Enums\MortgageLoanParticipantRole;
use App\Modules\Mortgage\Models\MortgageLoan;
use App\Modules\Mortgage\Models\MortgageLoanParticipant;
use App\Modules\Mortgage\ModuleFacts\MortgageModuleFactProvider;
use App\Support\ModuleFacts\Data\ModuleFactQuery;
use App\Support\ModuleFacts\Enums\ModuleFactCapability;
use App\Support\ModuleFacts\ModuleFactRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MortgageModuleFactProviderTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    protected function additionalTestMigrationModuleKeys(): array
    {
        return ['mortgage'];
    }

    public function test_home_purchase_date_is_mortgage_owned_and_ignores_refinances(): void
    {
        $contact = Contact::factory()->create();

        $this->loanForContact($contact, 'Purchase', '2021-08-31', 'older-purchase');
        $this->loanForContact($contact, 'Purchase', '2024-09-15', 'latest-purchase');
        $this->loanForContact($contact, 'Refinance', '2025-08-31', 'later-refinance');

        $registry = app(ModuleFactRegistry::class);

        if ($registry->find('mortgage.contact.home_purchase_date') === null) {
            $this->app->tag(MortgageModuleFactProvider::class, ModuleFactRegistry::PROVIDER_TAG);
            $registry = new ModuleFactRegistry($this->app);
        }
        $definition = $registry->require('mortgage.contact.home_purchase_date');

        $this->assertSame('mortgage', $definition->owner);
        $this->assertSame('Home purchase date', $definition->label);
        $this->assertTrue($definition->has(ModuleFactCapability::Annualizable));
        $this->assertFalse($definition->has(ModuleFactCapability::Writable));
        $this->assertSame('2024-09-15', $registry->resolve($definition->key, $contact)?->toDateString());
        $this->assertArrayNotHasKey('home_purchase_date', $contact->getAttributes());

        $notDue = Contact::query();
        $registry->apply(
            $definition->key,
            $notDue,
            ModuleFactQuery::annualMonthDay(Carbon::parse('2026-08-31')),
        );
        $this->assertSame([], $notDue->pluck('contacts.id')->all());

        $due = Contact::query();
        $registry->apply(
            $definition->key,
            $due,
            ModuleFactQuery::annualMonthDay(Carbon::parse('2026-09-15')),
        );
        $this->assertSame([$contact->getKey()], $due->pluck('contacts.id')->all());
    }

    private function loanForContact(
        Contact $contact,
        string $purpose,
        string $closedOn,
        string $fingerprint,
    ): MortgageLoan {
        $loan = MortgageLoan::query()->create([
            'source_system' => 'test',
            'source_fingerprint' => hash('sha256', $fingerprint),
            'loan_purpose' => $purpose,
            'closed_on' => $closedOn,
        ]);

        MortgageLoanParticipant::query()->create([
            'mortgage_loan_id' => $loan->getKey(),
            'contact_id' => $contact->getKey(),
            'role' => MortgageLoanParticipantRole::PrimaryBorrower,
            'position' => 1,
            'first_name' => $contact->first_name,
            'email' => $contact->email,
        ]);

        return $loan;
    }
}