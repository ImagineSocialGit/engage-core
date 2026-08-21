<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactImportProfileTreatmentTargetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_profile_only_shows_and_accepts_its_applicable_treatments(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        Config::set('contact_imports.profiles', [
            'known_export' => [
                'label' => 'Known Export',
                'filename_contains' => ['known export'],
                'aliases' => [
                    'email' => ['Email'],
                ],
            ],
        ]);
        Config::set('contact_import_treatments.profiles', [
            'known_export' => ['contact_tags'],
        ]);

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'csv' => UploadedFile::fake()->createWithContent(
                    'known-export.csv',
                    "Email\none@example.test\n",
                ),
            ]);

        $preview->assertOk();
        $preview->assertViewHas('treatmentDefinitions', function ($definitions): bool {
            return $definitions->pluck('key')->all() === ['contact_tags'];
        });

        $csvPath = $preview->viewData('csvPath');

        $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => $csvPath,
                'mapping' => [
                    'email' => 'Email',
                ],
                'treatments' => [
                    'contact_status' => [
                        'mode' => 'fixed',
                        'fixed_values' => ['anything'],
                    ],
                ],
            ])
            ->assertSessionHasErrors('treatments');

        $this->assertSame(0, Contact::query()->count());
    }

    public function test_unknown_file_keeps_the_full_advanced_treatment_catalog(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        Config::set('contact_imports.profiles', [
            'known_export' => [
                'label' => 'Known Export',
                'filename_contains' => ['known export'],
                'aliases' => [
                    'email' => ['Email'],
                ],
            ],
        ]);
        Config::set('contact_import_treatments.profiles', [
            'known_export' => ['contact_tags'],
        ]);

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'csv' => UploadedFile::fake()->createWithContent(
                    'mixed-contacts.csv',
                    "Email\none@example.test\n",
                ),
            ]);

        $preview->assertOk();
        $preview->assertViewHas('importProfile', null);
        $preview->assertViewHas('treatmentDefinitions', function ($definitions): bool {
            $keys = $definitions->pluck('key')->all();

            return in_array('contact_status', $keys, true)
                && in_array('contact_tags', $keys, true);
        });
    }
}