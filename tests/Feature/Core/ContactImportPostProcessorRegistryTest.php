<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessor;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Support\Contacts\ContactImportPostProcessorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ContactImportPostProcessorRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_known_processors_and_rejects_unavailable_keys(): void
    {
        $registry = new ContactImportPostProcessorRegistry();
        $registry
            ->registerProcessor(TestSecondImportPostProcessor::class)
            ->registerProcessor(TestFirstImportPostProcessor::class);

        $this->assertSame([
            'test_first' => ['value' => 'one'],
            'test_second' => ['value' => 'two'],
        ], $registry->normalizeConfig([
            'test_first' => ['value' => ' one '],
            'test_second' => ['value' => ' two '],
        ]));

        $this->assertSame([
            'test_first',
            'test_second',
        ], $registry->processors()->map(fn (ContactImportPostProcessor $processor): string => $processor->key())->all());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unavailable post-import processor [missing_processor]');

        $registry->normalizeConfig([
            'missing_processor' => [],
        ]);
    }

    public function test_processor_exception_becomes_reviewable_failed_outcome(): void
    {
        $registry = new ContactImportPostProcessorRegistry();
        $registry->registerProcessor(TestThrowingImportPostProcessor::class);

        $contact = Contact::factory()->create();
        $batch = ContactImportBatch::query()->create([
            'name' => 'Test import',
            'source' => 'test',
            'original_filename' => 'test.csv',
            'status' => ContactImportBatch::STATUS_PROCESSING,
            'imported_at' => now(),
            'contact_count' => 0,
            'successful_count' => 0,
            'failed_count' => 0,
            'meta' => [],
        ]);
        $occurrence = ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'row_number' => 2,
            'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => $contact->email,
            'row_fingerprint' => hash('sha256', 'test'),
            'meta' => [],
        ]);

        $results = $registry->process(
            new ContactImportContext(
                contact: $contact,
                batch: $batch,
                occurrence: $occurrence,
                row: ['Email' => $contact->email],
                mapping: ['email' => 'Email'],
            ),
            ['test_throwing' => ['value' => 'x']],
        );

        $this->assertSame(ContactImportPostProcessResult::STATE_FAILED, $results['test_throwing']->state);
        $this->assertSame('post_import_processor_failed', $results['test_throwing']->reasonCode);
        $this->assertTrue($results['test_throwing']->reviewRequired());
    }
}

class TestFirstImportPostProcessor implements ContactImportPostProcessor
{
    public function key(): string { return 'test_first'; }
    public function label(): string { return 'First'; }
    public function sort(): int { return 10; }
    public function normalizeConfig(array $config): array { return ['value' => trim((string) ($config['value'] ?? ''))]; }
    public function summary(array $config): string { return 'First'; }
    public function handle(ContactImportContext $context, array $config): ContactImportPostProcessResult { return ContactImportPostProcessResult::applied(); }
}

class TestSecondImportPostProcessor extends TestFirstImportPostProcessor
{
    public function key(): string { return 'test_second'; }
    public function label(): string { return 'Second'; }
    public function sort(): int { return 20; }
}

class TestThrowingImportPostProcessor extends TestFirstImportPostProcessor
{
    public function key(): string { return 'test_throwing'; }
    public function handle(ContactImportContext $context, array $config): ContactImportPostProcessResult
    {
        throw new \RuntimeException('processor exploded');
    }
}