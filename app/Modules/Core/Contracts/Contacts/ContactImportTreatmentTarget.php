<?php

namespace App\Modules\Core\Contracts\Contacts;

use App\Modules\Core\Data\Contacts\ContactImportTreatmentApplication;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentDefinition;

interface ContactImportTreatmentTarget
{
    public function available(): bool;

    public function definition(): ContactImportTreatmentDefinition;

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    public function normalizeValues(array $values): array;

    /**
     * @param array<int, string> $values
     * @return array<string, string>
     */
    public function fieldOverrides(array $values): array;

    public function apply(ContactImportTreatmentApplication $application): void;
}