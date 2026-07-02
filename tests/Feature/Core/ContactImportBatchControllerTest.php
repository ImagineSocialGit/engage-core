<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactImportBatchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_contact_import_batches(): void
    {
        $user = User::factory()->create();

        $importBatch = ContactImportBatch::factory()->create([
            'name' => 'June CSV Import',
            'source' => 'crm_csv',
            'original_filename' => 'june-leads.csv',
            'status' => ContactImportBatch::STATUS_COMPLETED,
            'imported_at' => now(),
            'successful_count' => 12,
            'failed_count' => 1,
        ]);

        Contact::factory()->count(2)->create([
            'contact_import_batch_id' => $importBatch->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.import-batches.index'));

        $response->assertOk();
        $response->assertSee('Import Batches');
        $response->assertSee('June CSV Import');
        $response->assertSee('june-leads.csv');
        $response->assertSee('Crm Csv');
        $response->assertSee('Completed');
        $response->assertSee('12');
    }

    public function test_it_shows_a_contact_import_batch_with_contacts(): void
    {
        $user = User::factory()->create();

        $importBatch = ContactImportBatch::factory()->create([
            'name' => 'June CSV Import',
            'source' => 'crm_csv',
            'original_filename' => 'june-leads.csv',
            'status' => ContactImportBatch::STATUS_COMPLETED,
            'imported_at' => now(),
            'successful_count' => 1,
            'failed_count' => 0,
        ]);

        $included = Contact::factory()->create([
            'name' => 'Jane Lead',
            'email' => 'jane@example.test',
            'phone' => '5551112222',
            'contact_import_batch_id' => $importBatch->id,
        ]);

        Contact::factory()->create([
            'name' => 'Ignored Lead',
            'email' => 'ignored@example.test',
            'contact_import_batch_id' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.import-batches.show', $importBatch));

        $response->assertOk();
        $response->assertSee('June CSV Import');
        $response->assertSee('june-leads.csv');
        $response->assertSee('Jane Lead');
        $response->assertSee('jane@example.test');
        $response->assertSee('5551112222');
        $response->assertDontSee('ignored@example.test');

        $this->assertSame($importBatch->id, $included->refresh()->contact_import_batch_id);
    }

    public function test_contacts_index_links_to_import_batches(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.index'));

        $response->assertOk();
        $response->assertSee('View Imports');
        $response->assertSee(route('crm.contacts.import-batches.index'), false);
    }
}