<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactImportProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlapping_csv_imports_preserve_row_provenance_and_historical_batch_membership(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $firstBatch = $this->importCsv(
            user: $user,
            filename: 'original-past-clients.csv',
            contents: implode("\n", [
                'First Name,Email,Source,Subsource',
                'Jane,JANE@example.test,Realtor.com,Space Coast',
                'Jane,jane@example.test,Realtor.com,Space Coast',
            ]),
            mapping: [
                'first_name' => 'First Name',
                'email' => 'Email',
                'source' => 'Source',
                'subsource' => 'Subsource',
            ],
        );

        $contact = Contact::query()
            ->where('email', 'jane@example.test')
            ->firstOrFail();

        $this->assertSame('original-past-clients.csv', $firstBatch->original_filename);
        $this->assertSame('Realtor.com', $contact->source);
        $this->assertSame('Space Coast', $contact->subsource);
        $this->assertSame(2, $firstBatch->successful_count);
        $this->assertSame(2, $firstBatch->importOccurrences()->count());
        $this->assertSame(1, $firstBatch->importedContactsQuery()->count());

        $firstOccurrences = $firstBatch->importOccurrences()
            ->orderBy('row_number')
            ->get();

        $this->assertSame([2, 3], $firstOccurrences->pluck('row_number')->all());
        $this->assertSame([
            ContactImportOccurrence::OUTCOME_CREATED,
            ContactImportOccurrence::OUTCOME_UPDATED,
        ], $firstOccurrences->pluck('outcome')->all());
        $this->assertSame(['email'], $firstOccurrences->pluck('identity_type')->unique()->values()->all());
        $this->assertSame(['jane@example.test'], $firstOccurrences->pluck('identity_value')->unique()->values()->all());
        $this->assertSame(64, strlen($firstOccurrences->firstOrFail()->row_fingerprint));

        $secondBatch = $this->importCsv(
            user: $user,
            filename: 'newer-prospects.txt',
            contents: implode("\n", [
                'First Name,Email,Source,Subsource',
                'Janet,jane@example.test,Facebook,Tampa',
            ]),
            mapping: [
                'first_name' => 'First Name',
                'email' => 'Email',
                'source' => 'Source',
                'subsource' => 'Subsource',
            ],
        );

        $contact->refresh();

        $this->assertSame(1, Contact::query()->count());
        $this->assertSame('Janet', $contact->first_name);
        $this->assertSame('Realtor.com', $contact->source);
        $this->assertSame('Space Coast', $contact->subsource);
        $this->assertSame($secondBatch->id, $contact->contact_import_batch_id);
        $this->assertSame(3, $contact->importOccurrences()->count());
        $this->assertSame(1, $firstBatch->importedContactsQuery()->whereKey($contact->id)->count());
        $this->assertSame(1, $secondBatch->importedContactsQuery()->whereKey($contact->id)->count());

        $secondOccurrence = $secondBatch->importOccurrences()->firstOrFail();

        $this->assertSame(ContactImportOccurrence::OUTCOME_UPDATED, $secondOccurrence->outcome);
        $this->assertSame('Facebook', $secondOccurrence->original_source);
        $this->assertSame('Tampa', $secondOccurrence->original_subsource);

        $resolvedFromFirstBatch = app(ContactFilterResolver::class)->resolve([
            'type' => 'import_batch',
            'import_batch_ids' => [$firstBatch->id],
        ]);

        $this->assertSame([$contact->id], $resolvedFromFirstBatch->pluck('id')->all());
    }

    public function test_occurrence_aware_queries_keep_legacy_import_batch_rows_visible(): void
    {
        $legacyBatch = ContactImportBatch::factory()->create();
        $legacyContact = Contact::factory()->create([
            'contact_import_batch_id' => $legacyBatch->id,
            'source' => 'manual',
        ]);

        $this->assertSame(
            [$legacyContact->id],
            $legacyBatch->importedContactsQuery()->orderBy('id')->pluck('id')->all(),
        );

        $resolved = app(ContactFilterResolver::class)->resolve([
            'type' => 'import_batch',
            'import_batch_ids' => [$legacyBatch->id],
        ]);

        $this->assertSame([$legacyContact->id], $resolved->pluck('id')->all());
    }

    private function importCsv(
        User $user,
        string $filename,
        string $contents,
        array $mapping,
    ): ContactImportBatch {
        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'csv' => UploadedFile::fake()->createWithContent($filename, $contents),
            ]);

        $preview->assertOk();

        $csvPath = $this->extractCsvPath($preview->getContent());

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => $csvPath,
                'mapping' => $mapping,
                'status_mapping' => [],
            ]);

        $response->assertRedirect(route('crm.contacts.index'));

        return ContactImportBatch::query()
            ->latest('id')
            ->firstOrFail();
    }

    private function extractCsvPath(string $html): string
    {
        preg_match('/name="csv_path"\s+value="([^"]+)"/', $html, $matches);

        $this->assertArrayHasKey(1, $matches, 'Unable to find csv_path hidden input.');

        return html_entity_decode($matches[1]);
    }
}