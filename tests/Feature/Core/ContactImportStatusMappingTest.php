<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactImportStatusMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_imported_status_values_to_active_contact_statuses(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $new = ContactStatus::query()->create([
            'key' => 'new',
            'name' => 'New',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $inProcess = ContactStatus::query()->create([
            'key' => 'in_process',
            'name' => 'In Process',
            'is_active' => true,
            'sort_order' => 20,
        ]);

        $csv = UploadedFile::fake()->createWithContent(
            'leads.csv',
            implode("\n", [
                'First Name,Email,Legacy Status',
                'Jane,jane@example.test,Fresh Lead',
                'Robert,robert@example.test,Working',
            ]),
        );

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'csv' => $csv,
            ]);

        $preview->assertOk();

        $csvPath = $this->extractCsvPath($preview->getContent());

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => $csvPath,
                'mapping' => [
                    'first_name' => 'First Name',
                    'email' => 'Email',
                    'import_status' => 'Legacy Status',
                ],
                'status_mapping' => [
                    'Fresh Lead' => $new->id,
                    'Working' => $inProcess->id,
                ],
            ]);

        $response->assertRedirect(route('crm.contacts.index'));

        $jane = Contact::query()->where('email', 'jane@example.test')->firstOrFail();
        $robert = Contact::query()->where('email', 'robert@example.test')->firstOrFail();

        $this->assertSame('Fresh Lead', data_get($jane->meta, 'import.original_status'));
        $this->assertSame('mapped', data_get($jane->meta, 'import.status_mapping.state'));
        $this->assertSame($new->id, data_get($jane->meta, 'import.status_mapping.contact_status_id'));

        $this->assertSame('Working', data_get($robert->meta, 'import.original_status'));
        $this->assertSame('mapped', data_get($robert->meta, 'import.status_mapping.state'));
        $this->assertSame($inProcess->id, data_get($robert->meta, 'import.status_mapping.contact_status_id'));

        $this->assertDatabaseHas('contact_workflow_profiles', [
            'contact_id' => $jane->id,
            'contact_status_id' => $new->id,
        ]);

        $this->assertDatabaseHas('contact_workflow_profiles', [
            'contact_id' => $robert->id,
            'contact_status_id' => $inProcess->id,
        ]);

        $batch = ContactImportBatch::query()->firstOrFail();

        $this->assertSame(2, data_get($batch->meta, 'status_mapping.mapped_count'));
        $this->assertSame(0, data_get($batch->meta, 'status_mapping.unmapped_count'));
        $this->assertFalse(data_get($batch->meta, 'status_mapping.review_required'));
    }

    public function test_it_preserves_and_flags_unmapped_imported_status_values(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $csv = UploadedFile::fake()->createWithContent(
            'leads.csv',
            implode("\n", [
                'First Name,Email,Legacy Status',
                'Jane,jane@example.test,Needs Review',
            ]),
        );

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'csv' => $csv,
            ]);

        $preview->assertOk();

        $csvPath = $this->extractCsvPath($preview->getContent());

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => $csvPath,
                'mapping' => [
                    'first_name' => 'First Name',
                    'email' => 'Email',
                    'import_status' => 'Legacy Status',
                ],
                'status_mapping' => [],
            ]);

        $response->assertRedirect(route('crm.contacts.index'));

        $contact = Contact::query()->where('email', 'jane@example.test')->firstOrFail();

        $this->assertSame('Needs Review', data_get($contact->meta, 'import.original_status'));
        $this->assertSame('unmapped', data_get($contact->meta, 'import.status_mapping.state'));
        $this->assertNull(data_get($contact->meta, 'import.status_mapping.contact_status_id'));

        $this->assertSame(0, ContactWorkflowProfile::query()->count());

        $batch = ContactImportBatch::query()->firstOrFail();

        $this->assertSame(0, data_get($batch->meta, 'status_mapping.mapped_count'));
        $this->assertSame(1, data_get($batch->meta, 'status_mapping.unmapped_count'));
        $this->assertSame(['Needs Review'], data_get($batch->meta, 'status_mapping.unmapped'));
        $this->assertTrue(data_get($batch->meta, 'status_mapping.review_required'));
    }

    public function test_it_marks_missing_imported_status_without_assigning_status(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $csv = UploadedFile::fake()->createWithContent(
            'leads.csv',
            implode("\n", [
                'First Name,Email,Legacy Status',
                'Jane,jane@example.test,',
            ]),
        );

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'csv' => $csv,
            ]);

        $preview->assertOk();

        $csvPath = $this->extractCsvPath($preview->getContent());

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => $csvPath,
                'mapping' => [
                    'first_name' => 'First Name',
                    'email' => 'Email',
                    'import_status' => 'Legacy Status',
                ],
                'status_mapping' => [],
            ]);

        $response->assertRedirect(route('crm.contacts.index'));

        $contact = Contact::query()->where('email', 'jane@example.test')->firstOrFail();

        $this->assertNull(data_get($contact->meta, 'import.original_status'));
        $this->assertSame('missing', data_get($contact->meta, 'import.status_mapping.state'));

        $this->assertSame(0, ContactWorkflowProfile::query()->count());

        $batch = ContactImportBatch::query()->firstOrFail();

        $this->assertSame(1, data_get($batch->meta, 'status_mapping.missing_count'));
        $this->assertFalse(data_get($batch->meta, 'status_mapping.review_required'));
    }

    public function test_it_rejects_inactive_status_mapping(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $inactive = ContactStatus::query()->create([
            'key' => 'inactive_status',
            'name' => 'Inactive Status',
            'is_active' => false,
            'sort_order' => 10,
        ]);

        $csv = UploadedFile::fake()->createWithContent(
            'leads.csv',
            implode("\n", [
                'First Name,Email,Legacy Status',
                'Jane,jane@example.test,Old Status',
            ]),
        );

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'csv' => $csv,
            ]);

        $preview->assertOk();

        $csvPath = $this->extractCsvPath($preview->getContent());

        $response = $this
            ->actingAs($user)
            ->from(route('crm.contacts.index'))
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => $csvPath,
                'mapping' => [
                    'first_name' => 'First Name',
                    'email' => 'Email',
                    'import_status' => 'Legacy Status',
                ],
                'status_mapping' => [
                    'Old Status' => $inactive->id,
                ],
            ]);

        $response->assertSessionHasErrors('status_mapping');

        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, ContactImportBatch::query()->count());
    }

    private function extractCsvPath(string $html): string
    {
        preg_match('/name="csv_path"\s+value="([^"]+)"/', $html, $matches);

        $this->assertArrayHasKey(1, $matches, 'Unable to find csv_path hidden input.');

        return html_entity_decode($matches[1]);
    }
}