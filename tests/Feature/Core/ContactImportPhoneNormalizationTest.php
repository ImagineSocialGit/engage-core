<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactImportPhoneNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_normalizes_common_us_phone_formats_to_e164(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $preview = $this->preview($user, implode("\n", [
            'Email,Phone',
            'formatted@example.test,"(615) 555-0100"',
            'ten-digits@example.test,6155550101',
            'country-code@example.test,16155550102',
            'already-e164@example.test,+16155550103',
        ]));

        $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $preview->viewData('csvPath'),
            'mapping' => [
                'email' => 'Email',
                'phone' => 'Phone',
            ],
            'treatments' => [],
        ])->assertRedirect(route('crm.contacts.index'));

        $this->assertSame(
            '+16155550100',
            Contact::query()->where('email', 'formatted@example.test')->value('phone'),
        );
        $this->assertSame(
            '+16155550101',
            Contact::query()->where('email', 'ten-digits@example.test')->value('phone'),
        );
        $this->assertSame(
            '+16155550102',
            Contact::query()->where('email', 'country-code@example.test')->value('phone'),
        );
        $this->assertSame(
            '+16155550103',
            Contact::query()->where('email', 'already-e164@example.test')->value('phone'),
        );

        $batch = ContactImportBatch::query()->sole();
        $this->assertSame(0, (int) data_get($batch->meta, 'phone_warning_count'));
    }

    public function test_import_ignores_noncanonical_unprefixed_phone_lengths(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $preview = $this->preview($user, implode("\n", [
            'Email,Phone',
            'short@example.test,615555010',
            'long@example.test,615555010000',
        ]));

        $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $preview->viewData('csvPath'),
            'mapping' => [
                'email' => 'Email',
                'phone' => 'Phone',
            ],
            'treatments' => [],
        ])->assertRedirect(route('crm.contacts.index'));

        $this->assertNull(
            Contact::query()->where('email', 'short@example.test')->value('phone'),
        );
        $this->assertNull(
            Contact::query()->where('email', 'long@example.test')->value('phone'),
        );

        $batch = ContactImportBatch::query()->sole();
        $this->assertSame(2, (int) data_get($batch->meta, 'phone_warning_count'));
    }

    public function test_update_import_does_not_replace_an_existing_phone_with_an_invalid_value(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'existing@example.test',
            'phone' => '+16155550100',
        ]);

        $preview = $this->preview(
            user: $user,
            contents: "Email,Phone\nexisting@example.test,615555010\n",
            mode: 'update',
        );

        $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $preview->viewData('csvPath'),
            'mapping' => [
                'email' => 'Email',
                'phone' => 'Phone',
            ],
            'treatments' => [],
        ])->assertRedirect(route('crm.contacts.index'));

        $this->assertSame('+16155550100', $contact->fresh()->phone);

        $batch = ContactImportBatch::query()->sole();
        $this->assertSame(1, (int) data_get($batch->meta, 'phone_warning_count'));
    }

    private function preview(User $user, string $contents, string $mode = 'add')
    {
        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'mode' => $mode,
                'csv' => UploadedFile::fake()->createWithContent(
                    'phone-normalization.csv',
                    $contents,
                ),
            ]);

        $preview->assertOk();

        return $preview;
    }
}