<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactImportTreatmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_profiles_source_column_values_across_the_staged_csv(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('crm.contacts.import.preview'), [
            'csv' => UploadedFile::fake()->createWithContent(
                'contacts.csv',
                implode("\n", [
                    'Email,Legacy Status',
                    'one@example.test,New',
                    'two@example.test,New',
                    'three@example.test,Working',
                    'four@example.test,',
                ]),
            ),
        ]);

        $response
            ->assertOk()
            ->assertSee('Import Treatment')
            ->assertSee('Apply based on a CSV field');

        $response->assertViewHas('columnProfiles', function (array $profiles): bool {
            $status = $profiles['Legacy Status'] ?? null;

            if (! is_array($status) || $status['blank_count'] !== 1) {
                return false;
            }

            $counts = collect($status['values'])->pluck('count', 'value')->all();

            return $counts === [
                'New' => 2,
                'Working' => 1,
            ];
        });
    }

    public function test_fixed_and_column_tag_treatments_are_additive_and_idempotent(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $csvPath = $this->preview($user, implode("\n", [
            'Email,Loan Type',
            'one@example.test,VA',
            'two@example.test,FHA',
            'one@example.test,VA',
        ]));

        $response = $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $csvPath,
            'mapping' => [
                'email' => 'Email',
            ],
            'treatments' => [
                'contact_tags' => [
                    'mode' => 'column',
                    'source_column' => 'Loan Type',
                    'value_map' => [
                        'va' => [
                            'source' => 'VA',
                            'custom' => 'loan-type:va, imported',
                        ],
                        'fha' => [
                            'source' => 'FHA',
                            'custom' => 'loan-type:fha, imported',
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect(route('crm.contacts.index'));

        $one = Contact::query()->where('email', 'one@example.test')->sole();
        $two = Contact::query()->where('email', 'two@example.test')->sole();

        $this->assertEqualsCanonicalizing(
            ['imported', 'loan-type:va'],
            ContactTag::query()->where('contact_id', $one->id)->pluck('tag')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['imported', 'loan-type:fha'],
            ContactTag::query()->where('contact_id', $two->id)->pluck('tag')->all(),
        );
        $this->assertSame(4, ContactTag::query()->count());

        $batch = ContactImportBatch::query()->sole();
        $this->assertSame(3, data_get($batch->meta, 'treatments.contact_tags.applied_count'));
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