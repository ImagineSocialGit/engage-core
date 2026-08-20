<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessor;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Support\Contacts\ContactImportPostProcessorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactImportPostProcessingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_post_import_behavior_is_visible_and_nonfatal_outcome_is_recorded(): void
    {
        Storage::fake('local');
        app(ContactImportPostProcessorRegistry::class)
            ->registerProcessor(TestBlockedContactImportPostProcessor::class);

        Config::set('contact_imports.profiles', [
            'known_export' => [
                'label' => 'Known Export',
                'filename_contains' => ['known export'],
                'aliases' => [
                    'email' => ['Email'],
                ],
                'post_import' => [
                    'test_blocked' => [
                        'reason' => 'needs review',
                    ],
                ],
            ],
        ]);

        $user = User::factory()->create();
        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'csv' => UploadedFile::fake()->createWithContent(
                    'known-export.csv',
                    "Email\nperson@example.test\n",
                ),
            ]);

        $preview->assertOk();
        $preview->assertSee('After each successful row');
        $preview->assertSee('This import will produce a reviewable test outcome.');

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => $preview->viewData('csvPath'),
                'mapping' => [
                    'email' => 'Email',
                ],
            ]);

        $response->assertRedirect(route('crm.contacts.index'));
        $response->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'post-import review needed'));

        $contact = Contact::query()->where('email', 'person@example.test')->firstOrFail();
        $occurrence = ContactImportOccurrence::query()->latest('id')->firstOrFail();
        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();

        $this->assertSame('blocked', data_get($occurrence->meta, 'post_import.test_blocked.state'));
        $this->assertSame('blocked', data_get($contact->meta, 'import.post_import.test_blocked.state'));
        $this->assertSame(1, data_get($batch->meta, 'post_import.processors.test_blocked.blocked_count'));
        $this->assertTrue((bool) data_get($batch->meta, 'post_import.review_required'));
        $this->assertSame(ContactImportBatch::STATUS_COMPLETED, $batch->status);
    }
}

class TestBlockedContactImportPostProcessor implements ContactImportPostProcessor
{
    public function key(): string { return 'test_blocked'; }
    public function label(): string { return 'Review test'; }
    public function sort(): int { return 999; }

    public function normalizeConfig(array $config): array
    {
        return ['reason' => trim((string) ($config['reason'] ?? ''))];
    }

    public function summary(array $config): string
    {
        return 'This import will produce a reviewable test outcome.';
    }

    public function handle(ContactImportContext $context, array $config): ContactImportPostProcessResult
    {
        return ContactImportPostProcessResult::blocked(
            reasonCode: 'test_blocked',
            message: $config['reason'] ?: 'blocked',
        );
    }
}