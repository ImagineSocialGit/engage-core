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

class ContactImportModeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_mode_updates_exact_email_matches_and_never_creates_missing_contacts(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $existing = Contact::factory()->create([
            'first_name' => 'Old',
            'email' => 'existing@example.test',
        ]);

        $csv = UploadedFile::fake()->createWithContent(
            'contact-updates.csv',
            implode("\n", [
                'First Name,Email',
                'Updated,existing@example.test',
                'Missing,missing@example.test',
            ]),
        );

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'mode' => 'update',
                'csv' => $csv,
            ]);

        $preview->assertOk();
        $this->assertSame('update', $preview->viewData('importMode'));

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => $preview->viewData('csvPath'),
                'mode' => 'add', // ignored: the staged import mode is server-owned
                'mapping' => [
                    'first_name' => 'First Name',
                    'email' => 'Email',
                ],
            ]);

        $response->assertRedirect(route('crm.contacts.index'));

        $this->assertSame('Updated', $existing->fresh()->first_name);
        $this->assertNull(Contact::query()->where('email', 'missing@example.test')->first());

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();
        $this->assertSame('update', data_get($batch->meta, 'import_mode'));
        $this->assertSame(1, data_get($batch->meta, 'update_not_found_count'));
        $this->assertSame(1, $batch->successful_count);
        $this->assertSame(1, $batch->failed_count);
    }

    public function test_update_mode_does_not_apply_import_profile_defaults(): void
    {
        Storage::fake('local');

        Config::set('contact_imports.profiles', [
            'known_update' => [
                'label' => 'Known Update',
                'filename_contains' => ['known update'],
                'defaults' => [
                    'source' => 'Database',
                    'subsource' => 'Server Default',
                ],
                'aliases' => [
                    'first_name' => ['First Name'],
                    'email' => ['Email'],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'first_name' => 'Before',
            'email' => 'person@example.test',
            'source' => null,
            'subsource' => null,
        ]);

        $csv = UploadedFile::fake()->createWithContent(
            'known-update.csv',
            "First Name,Email\nAfter,person@example.test\n",
        );

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'mode' => 'update',
                'csv' => $csv,
            ]);

        $preview->assertOk();

        $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => $preview->viewData('csvPath'),
                'mapping' => [
                    'first_name' => 'First Name',
                    'email' => 'Email',
                ],
            ])
            ->assertRedirect(route('crm.contacts.index'));

        $contact->refresh();
        $this->assertSame('After', $contact->first_name);
        $this->assertNull($contact->source);
        $this->assertNull($contact->subsource);

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();
        $this->assertEquals([], data_get($batch->meta, 'profile_defaults'));
        $this->assertEquals([], data_get($batch->meta, 'post_import_config'));
    }

    public function test_import_normalizes_utf8_bom_on_first_header_before_mapping_and_processing(): void
    {
        Storage::fake('local');

        Config::set('contact_imports.profiles', [
            'bom_contacts' => [
                'label' => 'BOM Contacts',
                'filename_contains' => ['bom contacts'],
                'aliases' => [
                    'first_name' => ['First Name'],
                    'last_name' => ['Last Name'],
                    'email' => ['Email'],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $csv = UploadedFile::fake()->createWithContent(
            'BOM Contacts.csv',
            "\xEF\xBB\xBFFirst Name,Last Name,Email\nJane,Doe,jane@example.test\n",
        );

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'mode' => 'add',
                'csv' => $csv,
            ]);

        $preview->assertOk();
        $this->assertSame(
            ['First Name', 'Last Name', 'Email'],
            $preview->viewData('headers')->all(),
        );
        $this->assertEquals([
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email',
        ], $preview->viewData('suggestedMapping'));

        $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => $preview->viewData('csvPath'),
                'mapping' => [
                    'first_name' => 'First Name',
                    'last_name' => 'Last Name',
                    'email' => 'Email',
                ],
            ])
            ->assertRedirect(route('crm.contacts.index'));

        $contact = Contact::query()
            ->where('email', 'jane@example.test')
            ->sole();

        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Doe', $contact->last_name);

        $batch = ContactImportBatch::query()->sole();
        $this->assertSame(
            ['First Name', 'Last Name', 'Email'],
            data_get($batch->meta, 'headers'),
        );
    }

    public function test_profile_preview_marks_only_required_and_recognized_fields_as_primary(): void
    {
        Storage::fake('local');

        Config::set('contact_imports.profiles', [
            'focused_export' => [
                'label' => 'Focused Export',
                'filename_contains' => ['focused export'],
                'aliases' => [
                    'first_name' => ['First Name'],
                    'email' => ['Email Address'],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $csv = UploadedFile::fake()->createWithContent(
            'focused-export.csv',
            "First Name,Email Address\nJane,jane@example.test\n",
        );

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'mode' => 'add',
                'csv' => $csv,
            ]);

        $preview->assertOk();
        $this->assertEqualsCanonicalizing(
            ['email', 'first_name'],
            $preview->viewData('primaryImportFieldKeys'),
        );
        $this->assertTrue($preview->viewData('hasAdvancedImportFields'));
    }
}