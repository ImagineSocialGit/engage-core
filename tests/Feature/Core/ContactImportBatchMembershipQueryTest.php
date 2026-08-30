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

    public function test_batch_detail_uses_the_paginator_total_without_a_duplicate_count(): void
    {
        $user = User::factory()->create();
        $batch = ContactImportBatch::factory()->create();

        Contact::factory()->count(2)->create([
            'contact_import_batch_id' => $batch->id,
        ]);

        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.import-batches.show', $batch));

        $response->assertOk();
        $response->assertViewHas(
            'importBatch',
            fn (ContactImportBatch $viewBatch): bool => (int) $viewBatch->contacts_count === 2,
        );

        $membershipCountQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'count(*) as aggregate')
                && str_contains($sql, 'contact_import_occurrences'),
        ));

        $this->assertCount(1, $membershipCountQueries);
    }

    public function test_occurrence_membership_index_is_batch_first_and_covering(): void
    {
        $columns = DB::table('information_schema.statistics')
            ->whereRaw('table_schema = database()')
            ->where('table_name', 'contact_import_occurrences')
            ->where('index_name', 'contact_import_occurrences_batch_contact_index')
            ->orderBy('seq_in_index')
            ->selectRaw('column_name as column_name')
            ->pluck('column_name')
            ->all();

        $this->assertSame(
            ['contact_import_batch_id', 'contact_id'],
            $columns,
        );
    }
}