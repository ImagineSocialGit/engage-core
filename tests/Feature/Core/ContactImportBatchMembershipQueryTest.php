<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContactImportBatchMembershipQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_membership_is_resolved_batch_first_with_legacy_pointer_fallback(): void
    {
        $targetBatch = ContactImportBatch::factory()->create();
        $laterBatch = ContactImportBatch::factory()->create();

        $historicalContact = Contact::factory()->create([
            'email' => 'historical@example.test',
            'contact_import_batch_id' => $laterBatch->id,
        ]);

        $legacyContact = Contact::factory()->create([
            'email' => 'legacy@example.test',
            'contact_import_batch_id' => $targetBatch->id,
        ]);

        Contact::factory()->create([
            'email' => 'unrelated@example.test',
            'contact_import_batch_id' => $laterBatch->id,
        ]);

        foreach ([2, 3] as $rowNumber) {
            ContactImportOccurrence::query()->create([
                'contact_import_batch_id' => $targetBatch->id,
                'contact_id' => $historicalContact->id,
                'row_number' => $rowNumber,
                'outcome' => ContactImportOccurrence::OUTCOME_UPDATED,
                'identity_type' => 'email',
                'identity_value' => $historicalContact->email,
                'row_fingerprint' => hash('sha256', "target:{$rowNumber}"),
            ]);
        }

        $batchQuery = $targetBatch->importedContactsQuery();
        $batchSql = strtolower($batchQuery->toSql());

        $this->assertStringContainsString('contact_import_occurrences', $batchSql);
        $this->assertStringContainsString(' union ', $batchSql);
        $this->assertStringNotContainsString('exists', $batchSql);
        $this->assertSame(
            [$historicalContact->id, $legacyContact->id],
            $batchQuery->orderBy('contacts.id')->pluck('contacts.id')->all(),
        );

        $filterQuery = app(ContactFilterResolver::class)->query([
            'type' => 'import_batch',
            'import_batch_ids' => [$targetBatch->id],
        ]);

        $this->assertStringNotContainsString('exists', strtolower($filterQuery->toSql()));
        $this->assertSame(
            [$historicalContact->id, $legacyContact->id],
            $filterQuery->pluck('contacts.id')->all(),
        );
    }


}