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


    public function test_it_shows_only_contacts_from_the_selected_import_batch(): void
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
            'email' => 'jane@example.test',
            'contact_import_batch_id' => $importBatch->id,
        ]);

        Contact::factory()->create([
            'email' => 'ignored@example.test',
            'contact_import_batch_id' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.import-batches.show', $importBatch));

        $response->assertOk();

        $this->assertSame($importBatch->id, $included->refresh()->contact_import_batch_id);
    }


}