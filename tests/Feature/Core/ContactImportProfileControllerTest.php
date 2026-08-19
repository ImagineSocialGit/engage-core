<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactImportProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_filename_prefills_mapping_and_applies_server_owned_defaults(): void
    {
        Storage::fake('local');

        Config::set('contact_imports.profiles', [
            'known_export' => [
                'label' => 'Known Export',
                'description' => 'Known client export.',
                'filename_contains' => ['known export'],
                'defaults' => [
                    'source' => 'Database',
                    'subsource' => 'Known Export',
                ],
                'aliases' => [
                    'first_name' => ['First Name'],
                    'last_name' => ['Last Name'],
                    'email' => ['Email Address'],
                ],
            ],
            'other_export' => [
                'label' => 'Other Export',
                'filename_contains' => ['other export'],
                'defaults' => [
                    'source' => 'Tampered Source',
                    'subsource' => 'Tampered Export',
                ],
                'aliases' => [
                    'email' => ['Email Address'],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $csv = UploadedFile::fake()->createWithContent(
            'known-export.csv',
            "First Name,Last Name,Email Address\nJane,Lead,jane@example.test\n",
        );

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), ['csv' => $csv]);

        $preview->assertOk();
        $preview->assertViewHas('importProfile', fn ($profile): bool => $profile?->key === 'known_export');
        $preview->assertViewHas('suggestedMapping', [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email Address',
        ]);
        $preview->assertSee('Import profile: Known Export');
        $preview->assertDontSee('name="profile_key"', false);

        $csvPath = $preview->viewData('csvPath');

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => $csvPath,
                'profile_key' => 'other_export', // ignored: profile identity is server-owned
                'mapping' => [
                    'first_name' => 'First Name',
                    'last_name' => 'Last Name',
                    'email' => 'Email Address',
                ],
            ]);

        $response->assertRedirect(route('crm.contacts.index'));

        $contact = Contact::query()->where('email', 'jane@example.test')->firstOrFail();
        $this->assertSame('Database', $contact->source);
        $this->assertSame('Known Export', $contact->subsource);

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();
        $this->assertSame('known_export', data_get($batch->meta, 'profile_key'));
        $this->assertSame('Database', data_get($batch->meta, 'profile_defaults.source'));
    }
}