<?php

namespace Tests\Feature\Mortgage;

use App\Modules\Campaigns\Jobs\EmitDueAnnualTouchAutomationEventsJob;
use App\Modules\Core\Models\Contact;
use App\Modules\Mortgage\ModuleFacts\MortgageModuleFactProvider;
use App\Modules\Mortgage\Enums\MortgageLoanParticipantRole;
use App\Modules\Mortgage\Models\MortgageLoan;
use App\Modules\Mortgage\Models\MortgageLoanParticipant;
use App\Support\ModuleFacts\ModuleFactRegistry;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MortgageAnnualTouchDateSourceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    protected function additionalTestMigrationModuleKeys(): array
    {
        return ['mortgage'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_home_purchase_source_uses_latest_purchase_closing_without_copying_housing_data_to_contact(): void
    {
        config()->set('client.timezone', 'UTC');

        $contact = Contact::query()->create([
            'first_name' => 'Casey',
            'email' => 'casey@example.test',
        ]);

        $this->loanForContact(
            contact: $contact,
            purpose: 'Purchase',
            closedOn: '2021-08-31',
            fingerprint: 'older-purchase',
        );
        $this->loanForContact(
            contact: $contact,
            purpose: 'Purchase',
            closedOn: '2024-09-15',
            fingerprint: 'latest-purchase',
        );
        $this->loanForContact(
            contact: $contact,
            purpose: 'Refinance',
            closedOn: '2025-08-31',
            fingerprint: 'later-refinance',
        );

        $registry = app(ModuleFactRegistry::class);

        if ($registry->find('mortgage.contact.home_purchase_date') === null) {
            $this->app->tag(MortgageModuleFactProvider::class, ModuleFactRegistry::PROVIDER_TAG);
            $registry = new ModuleFactRegistry($this->app);
        }

        $this->app->instance(ModuleFactRegistry::class, $registry);

        $job = new EmitDueAnnualTouchAutomationEventsJob('2026-09-15');
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $event = AutomationEventOutboxEvent::query()
            ->where('event_key', 'campaign_touch.annual_date_due')
            ->sole();

        $this->assertSame($contact->getKey(), $event->contact_id);
        $this->assertSame(
            'mortgage.contact.home_purchase_date',
            data_get($event->payload, 'annual_date.source_key'),
        );
        $this->assertSame('2024-09-15', data_get($event->payload, 'annual_date.source_date'));
        $this->assertSame(2, data_get($event->payload, 'annual_date.occurrence_number'));
        $this->assertSame('2nd', data_get($event->payload, 'annual_date.occurrence_ordinal'));
        $this->assertSame('mortgage', data_get($event->meta, 'source_module'));
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