<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessor;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessorBatchFinalizer;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessorInputProvider;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Support\Contacts\ContactImportPostProcessorRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContactImportPostProcessorExtensionContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_inputs_are_exposed_and_merged_only_for_configured_processors(): void
    {
        $registry = new ContactImportPostProcessorRegistry();
        $registry->registerProcessor(TestOperatorInputPostProcessor::class);

        $configured = [
            'test_operator_input' => [
                'server_key' => 'protected',
            ],
        ];

        $this->assertEquals([
            [
                'key' => 'test_operator_input',
                'label' => 'Test operator input',
                'inputs' => [[
                    'key' => 'when',
                    'label' => 'When',
                    'type' => 'datetime-local',
                    'required' => true,
                ]],
            ],
        ], $registry->inputDefinitions($configured));

        $effective = $registry->withSubmittedInputs(
            configured: $configured,
            submitted: [
                'test_operator_input' => [
                    'when' => '2026-08-21T10:00',
                    'server_key' => 'tampered',
                ],
            ],
        );

        $this->assertSame('protected', $effective['test_operator_input']['server_key']);
        $this->assertSame('2026-08-21T10:00', $effective['test_operator_input']['when']);
    }

    public function test_operator_input_for_an_unconfigured_processor_is_rejected(): void
    {
        $registry = new ContactImportPostProcessorRegistry();
        $registry->registerProcessor(TestOperatorInputPostProcessor::class);

        $this->expectException(ValidationException::class);

        $registry->withSubmittedInputs(
            configured: [],
            submitted: [
                'test_operator_input' => [
                    'when' => '2026-08-21T10:00',
                ],
            ],
        );
    }

    public function test_configured_batch_finalizers_run_after_row_processing_contract(): void
    {
        $registry = new ContactImportPostProcessorRegistry();
        $registry->registerProcessor(TestOperatorInputPostProcessor::class);

        $batch = ContactImportBatch::factory()->create();

        $results = $registry->finalizeBatch(
            batch: $batch,
            configured: [
                'test_operator_input' => [
                    'server_key' => 'protected',
                    'when' => '2026-08-21T10:00',
                ],
            ],
        );

        $this->assertSame(
            ContactImportPostProcessResult::STATE_APPLIED,
            $results['test_operator_input']->state,
        );
        $this->assertSame(
            (int) $batch->getKey(),
            $results['test_operator_input']->meta['batch_id'],
        );
    }
}

class TestOperatorInputPostProcessor implements
    ContactImportPostProcessor,
    ContactImportPostProcessorInputProvider,
    ContactImportPostProcessorBatchFinalizer
{
    public function key(): string
    {
        return 'test_operator_input';
    }

    public function label(): string
    {
        return 'Test operator input';
    }

    public function sort(): int
    {
        return 10;
    }

    public function normalizeConfig(array $config): array
    {
        return [
            'server_key' => trim((string) ($config['server_key'] ?? '')),
            'when' => isset($config['when'])
                ? trim((string) $config['when'])
                : null,
        ];
    }

    public function summary(array $config): string
    {
        return 'Test';
    }

    public function inputDefinitions(array $config): array
    {
        return [[
            'key' => 'when',
            'label' => 'When',
            'type' => 'datetime-local',
            'required' => true,
        ]];
    }

    public function withSubmittedInputs(
        array $config,
        array $submitted,
    ): array {
        return [
            'server_key' => $config['server_key'],
            'when' => trim((string) ($submitted['when'] ?? '')),
        ];
    }

    public function handle(
        ContactImportContext $context,
        array $config,
    ): ContactImportPostProcessResult {
        return ContactImportPostProcessResult::applied();
    }

    public function finalizeBatch(
        ContactImportBatch $batch,
        array $config,
    ): ContactImportPostProcessResult {
        return ContactImportPostProcessResult::applied([
            'batch_id' => (int) $batch->getKey(),
        ]);
    }
}