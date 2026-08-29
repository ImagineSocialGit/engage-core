<?php

namespace App\Modules\Core\Contracts\Contacts;

interface ContactImportPostProcessorOperatorConfigProvider extends ContactImportPostProcessorInputProvider
{
    /**
     * Build the server-owned config that should be presented to an operator.
     *
     * A processor may use this seam to expose an import-wide decision even
     * when the detected import profile did not explicitly configure it.
     *
     * @param array<string, mixed>|null $configured
     * @return array<string, mixed>
     */
    public function operatorConfig(?array $configured): array;

    /**
     * Decide whether the operator-resolved config should remain in the
     * durable post-import processing plan.
     *
     * @param array<string, mixed> $config
     */
    public function shouldProcess(array $config): bool;
}