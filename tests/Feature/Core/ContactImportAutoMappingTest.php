<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactImportAutoMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unrecognized_csv_receives_clear_header_mapping_suggestions(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $response = $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            [
                'csv' => UploadedFile::fake()->createWithContent(
                    'legacy-contacts.csv',
                    implode("\n", [
                        'First Name,Surname,Email Address,Mobile,Lead Source,Legacy Status',
                        'Jane,Smith,jane@example.test,5551112222,Referral,Active Client',
                    ]),
                ),
            ],
        );

        $response->assertOk();
        $response->assertViewHas('importProfile', fn ($profile): bool => $profile === null);
        $response->assertViewHas('suggestedMapping', [
            'first_name' => 'First Name',
            'last_name' => 'Surname',
            'email' => 'Email Address',
            'phone' => 'Mobile',
            'source' => 'Lead Source',
            'import_status' => 'Legacy Status',
        ]);
        $response->assertViewHas('treatmentDefaults', function (array $defaults): bool {
            return data_get($defaults, 'contact_status.mode') === 'column'
                && data_get($defaults, 'contact_status.source_column') === 'Legacy Status';
        });
        $response->assertSee('Use legacy status');
    }

    public function test_ambiguous_headers_are_not_guessed(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $response = $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            [
                'csv' => UploadedFile::fake()->createWithContent(
                    'ambiguous.csv',
                    implode("\n", [
                        'Primary Email,Primary Email Address,First Name',
                        'one@example.test,two@example.test,Jane',
                    ]),
                ),
            ],
        );

        $response->assertOk();
        $suggested = $response->viewData('suggestedMapping');

        $this->assertSame('First Name', $suggested['first_name'] ?? null);
        $this->assertArrayNotHasKey('email', $suggested);
    }
}