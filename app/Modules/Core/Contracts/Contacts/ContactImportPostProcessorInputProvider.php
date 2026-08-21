<?php

namespace App\Modules\Core\Contracts\Contacts;

interface ContactImportPostProcessorInputProvider
{
    /**
     * Describe operator inputs owned by this configured post-import processor.
     *
     * @param array<string, mixed> $config
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     type: string,
     *     description?: string|null,
     *     required?: bool
     * }>
     */
    public function inputDefinitions(array $config): array;

    /**
     * Merge and validate operator input into server-owned processor config.
     * Browser input must never be allowed to replace server-owned identity
     * such as Campaign keys.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    public function withSubmittedInputs(
        array $config,
        array $submitted,
    ): array;
}