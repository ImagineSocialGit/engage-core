<?php

namespace App\Modules\Core\Contracts\Contacts;

use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;

interface ContactImportPostProcessor
{
    public function key(): string;

    public function label(): string;

    public function sort(): int;

    /**
     * Validate and normalize one profile's processor configuration.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function normalizeConfig(array $config): array;

    /**
     * @param array<string, mixed> $config
     */
    public function summary(array $config): string;

    /**
     * @param array<string, mixed> $config
     */
    public function handle(
        ContactImportContext $context,
        array $config,
    ): ContactImportPostProcessResult;
}