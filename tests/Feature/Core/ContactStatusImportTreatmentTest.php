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

class ContactStatusImportTreatmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_status_treatment_applies_one_status_to_all_imported_contacts(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $status = $this->contactStatus('nurture', 'Nurture');
        $csvPath = $this->preview($user, implode("\n", [
            'First Name,Email',
            'Jane,jane@example.test',
            'Robert,robert@example.test',
        ]));

        $response = $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $csvPath,
            'mapping' => [
                'first_name' => 'First Name',
                'email' => 'Email',
            ],
            'treatments' => [
                'contact_status' => [
                    'mode' => 'fixed',
                    'fixed_values' => [(string) $status->id],
                ],
            ],
        ]);

        $response->assertRedirect(route('crm.contacts.index'));

        $this->assertSame(2, ContactWorkflowProfile::query()
            ->where('contact_status_id', $status->id)
            ->count());

        $batch = ContactImportBatch::query()->sole();
        $this->assertSame(2, data_get($batch->meta, 'treatments.contact_status.applied_count'));
        $this->assertSame(0, data_get($batch->meta, 'treatments.contact_status.unmapped_count'));
    }

    public function test_status_treatment_maps_source_values_and_preserves_unmapped_values_for_review(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $new = $this->contactStatus('new', 'New');
        $working = $this->contactStatus('working', 'Working');
        $csvPath = $this->preview($user, implode("\n", [
            'Email,Legacy Status',
            'jane@example.test,Fresh Lead',
            'robert@example.test,Working',
            'sam@example.test,Needs Review',
        ]));

        $response = $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $csvPath,
            'mapping' => [
                'email' => 'Email',
                'import_status' => 'Legacy Status',
            ],
            'treatments' => [
                'contact_status' => [
                    'mode' => 'column',
                    'source_column' => 'Legacy Status',
                    'value_map' => [
                        'fresh' => [
                            'source' => 'Fresh Lead',
                            'values' => [(string) $new->id],
                        ],
                        'working' => [
                            'source' => 'Working',
                            'values' => [(string) $working->id],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect(route('crm.contacts.index'));

        $jane = Contact::query()->where('email', 'jane@example.test')->firstOrFail();
        $robert = Contact::query()->where('email', 'robert@example.test')->firstOrFail();
        $sam = Contact::query()->where('email', 'sam@example.test')->firstOrFail();

        $this->assertDatabaseHas('contact_workflow_profiles', [
            'contact_id' => $jane->id,
            'contact_status_id' => $new->id,
        ]);
        $this->assertDatabaseHas('contact_workflow_profiles', [
            'contact_id' => $robert->id,
            'contact_status_id' => $working->id,
        ]);
        $this->assertDatabaseMissing('contact_workflow_profiles', [
            'contact_id' => $sam->id,
        ]);

        $this->assertSame('Needs Review', data_get($sam->meta, 'import.original_status'));
        $this->assertSame('unmapped', data_get($sam->meta, 'import.treatments.contact_status.state'));

        $batch = ContactImportBatch::query()->sole();
        $this->assertSame(2, data_get($batch->meta, 'treatments.contact_status.applied_count'));
        $this->assertSame(1, data_get($batch->meta, 'treatments.contact_status.unmapped_count'));
        $this->assertTrue(data_get($batch->meta, 'treatments.contact_status.review_required'));
    }

    public function test_status_treatment_rejects_inactive_destination_status(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $inactive = ContactStatus::query()->create([
            'key' => 'inactive_status',
            'name' => 'Inactive Status',
            'is_active' => false,
            'sort_order' => 10,
        ]);
        $csvPath = $this->preview($user, implode("\n", [
            'Email',
            'jane@example.test',
        ]));

        $response = $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $csvPath,
            'mapping' => [
                'email' => 'Email',
            ],
            'treatments' => [
                'contact_status' => [
                    'mode' => 'fixed',
                    'fixed_values' => [(string) $inactive->id],
                ],
            ],
        ]);

        $response->assertSessionHasErrors('treatments.contact_status');
        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, ContactImportBatch::query()->count());
    }

    private function contactStatus(string $key, string $name): ContactStatus
    {
        return ContactStatus::query()->create([
            'key' => $key,
            'name' => $name,
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

    private function preview(User $user, string $contents): string
    {
        $response = $this->actingAs($user)->post(route('crm.contacts.import.preview'), [
            'csv' => UploadedFile::fake()->createWithContent('contacts.csv', $contents),
        ]);

        $response->assertOk();

        preg_match('/name="csv_path"\s+value="([^"]+)"/', $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches, 'Unable to find csv_path hidden input.');

        return html_entity_decode($matches[1]);
    }
}