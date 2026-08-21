<?php

namespace App\Modules\Core\Support\Contacts;

use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessor;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessorBatchFinalizer;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessorInputProvider;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\ContactImportBatch;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class ContactImportPostProcessorRegistry
{
    /** @var array<string, class-string<ContactImportPostProcessor>> */
    private array $processors = [];

    /** @param class-string<ContactImportPostProcessor> $processor */
    public function registerProcessor(string $processor): self
    {
        if (! is_subclass_of($processor, ContactImportPostProcessor::class)) {
            throw new InvalidArgumentException(
                $processor.' must implement '.ContactImportPostProcessor::class.'.',
            );
        }

        $instance = app($processor);
        $key = $this->normalizeKey($instance->key());

        if (isset($this->processors[$key]) && $this->processors[$key] !== $processor) {
            throw new InvalidArgumentException(
                "Contact import post-processor key [{$key}] is registered more than once.",
            );
        }

        $this->processors[$key] = $processor;

        return $this;
    }

    /** @return Collection<int, ContactImportPostProcessor> */
    public function processors(): Collection
    {
        return collect($this->processors)
            ->map(fn (string $class): ContactImportPostProcessor => app($class))
            ->sort(function (ContactImportPostProcessor $left, ContactImportPostProcessor $right): int {
                $sort = $left->sort() <=> $right->sort();

                return $sort !== 0 ? $sort : $left->key() <=> $right->key();
            })
            ->values();
    }

    /**
     * @param mixed $configured
     * @return array<string, array<string, mixed>>
     */
    public function normalizeConfig(mixed $configured): array
    {
        if (! is_array($configured) || ($configured !== [] && array_is_list($configured))) {
            throw new InvalidArgumentException(
                'Contact import profile [post_import] must be a keyed array.',
            );
        }

        $available = $this->processors()->keyBy(
            fn (ContactImportPostProcessor $processor): string => $processor->key(),
        );
        $normalized = [];

        foreach ($configured as $key => $config) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException(
                    'Contact import profile [post_import] keys must be non-empty strings.',
                );
            }

            $key = $this->normalizeKey($key);
            $processor = $available->get($key);

            if (! $processor instanceof ContactImportPostProcessor) {
                throw new InvalidArgumentException(
                    "Contact import profile references unavailable post-import processor [{$key}].",
                );
            }

            if (! is_array($config) || ($config !== [] && array_is_list($config))) {
                throw new InvalidArgumentException(
                    "Contact import profile [post_import.{$key}] must be a keyed array.",
                );
            }

            $normalized[$key] = $processor->normalizeConfig($config);
        }

        return $normalized;
    }

    /**
     * @param array<string, array<string, mixed>> $configured
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     inputs: array<int, array<string, mixed>>
     * }>
     */
    public function inputDefinitions(array $configured): array
    {
        $configured = $this->normalizeConfig($configured);
        $definitions = [];

        foreach ($this->processors() as $processor) {
            $config = $configured[$processor->key()] ?? null;

            if (! is_array($config)
                || ! $processor instanceof ContactImportPostProcessorInputProvider
            ) {
                continue;
            }

            $inputs = $processor->inputDefinitions($config);

            if ($inputs === []) {
                continue;
            }

            $definitions[] = [
                'key' => $processor->key(),
                'label' => $processor->label(),
                'inputs' => $inputs,
            ];
        }

        return $definitions;
    }

    /**
     * Apply operator input only through processors configured server-side by
     * the detected import profile. Unconfigured processor keys are rejected.
     *
     * @param array<string, array<string, mixed>> $configured
     * @param array<string, mixed> $submitted
     * @return array<string, array<string, mixed>>
     */
    public function withSubmittedInputs(
        array $configured,
        array $submitted,
    ): array {
        $configured = $this->normalizeConfig($configured);

        if ($submitted !== [] && array_is_list($submitted)) {
            throw ValidationException::withMessages([
                'post_import_inputs' => 'Post-import inputs must be a keyed array.',
            ]);
        }

        $normalizedSubmitted = [];

        foreach ($submitted as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                throw ValidationException::withMessages([
                    'post_import_inputs' => 'Post-import input keys must be non-empty strings.',
                ]);
            }

            $normalizedSubmitted[$this->normalizeKey($key)] = $value;
        }

        foreach (array_keys($normalizedSubmitted) as $key) {
            if (! array_key_exists($key, $configured)) {
                throw ValidationException::withMessages([
                    "post_import_inputs.{$key}" => 'This post-import behavior is not configured for the selected import profile.',
                ]);
            }
        }

        $processors = $this->processors()->keyBy(
            fn (ContactImportPostProcessor $processor): string => $processor->key(),
        );

        foreach ($configured as $key => $config) {
            $processor = $processors->get($key);
            $processorSubmitted = $normalizedSubmitted[$key] ?? [];

            if (! $processor instanceof ContactImportPostProcessorInputProvider) {
                if ($processorSubmitted !== []) {
                    throw ValidationException::withMessages([
                        "post_import_inputs.{$key}" => 'This post-import behavior does not accept operator input.',
                    ]);
                }

                continue;
            }

            if (! is_array($processorSubmitted)
                || ($processorSubmitted !== [] && array_is_list($processorSubmitted))
            ) {
                throw ValidationException::withMessages([
                    "post_import_inputs.{$key}" => 'Post-import behavior input must be a keyed array.',
                ]);
            }

            $configured[$key] = $processor->normalizeConfig(
                $processor->withSubmittedInputs(
                    config: $config,
                    submitted: $processorSubmitted,
                ),
            );
        }

        return $configured;
    }

    /**
     * @param array<string, array<string, mixed>> $configured
     * @return array<int, array{key: string, label: string, summary: string}>
     */
    public function summaries(array $configured): array
    {
        $configured = $this->normalizeConfig($configured);
        $summaries = [];

        foreach ($this->processors() as $processor) {
            $config = $configured[$processor->key()] ?? null;

            if (! is_array($config)) {
                continue;
            }

            $summaries[] = [
                'key' => $processor->key(),
                'label' => $processor->label(),
                'summary' => $processor->summary($config),
            ];
        }

        return $summaries;
    }

    /**
     * Post-import processors are intentionally non-fatal to the durable Contact row.
     * Expected and unexpected processor failures are returned as reviewable outcomes.
     *
     * @param array<string, array<string, mixed>> $configured
     * @return array<string, ContactImportPostProcessResult>
     */
    public function process(
        ContactImportContext $context,
        array $configured,
    ): array {
        $configured = $this->normalizeConfig($configured);
        $results = [];

        foreach ($this->processors() as $processor) {
            $config = $configured[$processor->key()] ?? null;

            if (! is_array($config)) {
                continue;
            }

            try {
                $results[$processor->key()] = $processor->handle($context, $config);
            } catch (Throwable $exception) {
                report($exception);

                $results[$processor->key()] = ContactImportPostProcessResult::failed(
                    reasonCode: 'post_import_processor_failed',
                    message: $exception->getMessage(),
                    meta: [
                        'exception' => $exception::class,
                    ],
                );
            }
        }

        return $results;
    }

    /**
     * Batch finalizers run after all row processing has completed. A finalizer
     * failure is reviewable and does not roll back durable Contact imports.
     *
     * @param array<string, array<string, mixed>> $configured
     * @return array<string, ContactImportPostProcessResult>
     */
    public function finalizeBatch(
        ContactImportBatch $batch,
        array $configured,
    ): array {
        $configured = $this->normalizeConfig($configured);
        $results = [];

        foreach ($this->processors() as $processor) {
            $config = $configured[$processor->key()] ?? null;

            if (! is_array($config)
                || ! $processor instanceof ContactImportPostProcessorBatchFinalizer
            ) {
                continue;
            }

            try {
                $results[$processor->key()] = $processor->finalizeBatch(
                    batch: $batch,
                    config: $config,
                );
            } catch (Throwable $exception) {
                report($exception);

                $results[$processor->key()] = ContactImportPostProcessResult::failed(
                    reasonCode: 'post_import_batch_finalizer_failed',
                    message: $exception->getMessage(),
                    meta: [
                        'exception' => $exception::class,
                    ],
                );
            }
        }

        return $results;
    }

    /**
     * @param array<string, ContactImportPostProcessResult> $results
     * @return array<string, array<string, mixed>>
     */
    public function resultsMeta(array $results): array
    {
        $meta = [];

        foreach ($results as $key => $result) {
            $meta[$key] = $result->toMeta();
        }

        return $meta;
    }

    private function normalizeKey(string $key): string
    {
        return str_replace('-', '_', strtolower(trim($key)));
    }
}