<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Contracts\Contacts\ContactImportHandler;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactImportHandlerContextIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_handler_receives_persisted_core_occurrence_and_mapped_row_context(): void
    {
        Storage::fake('local');
        CapturingContactImportHandler::reset();

        app(ContactImportRegistry::class)
            ->registerHandler(CapturingContactImportHandler::class);

        $user = User::factory()->create();

        $preview = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'csv' => UploadedFile::fake()->createWithContent(
                    'handler-context.csv',
                    "First Name,Email,Source\nJane,jane@example.test,Realtor.com",
                ),
            ]);

        $preview->assertOk();

        preg_match('/name="csv_path"\s+value="([^"]+)"/', $preview->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.process'), [
                'csv_path' => html_entity_decode($matches[1]),
                'mapping' => [
                    'first_name' => 'First Name',
                    'email' => 'Email',
                    'source' => 'Source',
                ],
                'treatments' => [],
            ]);

        $response->assertRedirect(route('crm.contacts.index'));

        $this->assertTrue(CapturingContactImportHandler::$called);
        $this->assertTrue(CapturingContactImportHandler::$occurrencePersisted);
        $this->assertSame('Realtor.com', CapturingContactImportHandler::$mappedSource);
        $this->assertSame('jane@example.test', CapturingContactImportHandler::$contactEmail);
        $this->assertDatabaseCount('contact_import_occurrences', 1);
    }
}

final class CapturingContactImportHandler implements ContactImportHandler
{
    public static bool $called = false;
    public static bool $occurrencePersisted = false;
    public static ?string $mappedSource = null;
    public static ?string $contactEmail = null;

    public static function reset(): void
    {
        self::$called = false;
        self::$occurrencePersisted = false;
        self::$mappedSource = null;
        self::$contactEmail = null;
    }

    public function handle(ContactImportContext $context): void
    {
        self::$called = true;
        self::$occurrencePersisted = $context->occurrence->exists
            && ContactImportOccurrence::query()->whereKey($context->occurrence->id)->exists();
        self::$mappedSource = $context->mappedValue('source');
        self::$contactEmail = $context->contact->email;
    }
}