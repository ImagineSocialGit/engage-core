<?php

namespace App\Modules\Core\Contracts\Contacts;

use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\ContactImportBatch;

interface ContactImportPostProcessorBatchFinalizer
{
    /**
     * Finalize batch-level behavior only after every import row has completed.
     *
     * @param array<string, mixed> $config
     */
    public function finalizeBatch(
        ContactImportBatch $batch,
        array $config,
    ): ContactImportPostProcessResult;
}