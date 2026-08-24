<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;

final class CampaignEligibilityReevaluationGuard
{
    public function mayEvaluate(Contact $contact): bool
    {
        if (! is_numeric($contact->contact_import_batch_id)
            || (int) $contact->contact_import_batch_id < 1
        ) {
            return true;
        }

        return ! ContactImportBatch::query()
            ->whereKey((int) $contact->contact_import_batch_id)
            ->where('status', ContactImportBatch::STATUS_PROCESSING)
            ->exists();
    }
}