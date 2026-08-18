<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Contracts\MessageChainExecutionContextProvider;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CampaignMessageChainExecutionContextProvider implements MessageChainExecutionContextProvider
{
    private const SURFACE = 'campaigns';

    public function supports(MessageChainEnrollment $enrollment): bool
    {
        $enrollment->loadMissing(['recipient', 'context', 'origin']);

        return $enrollment->surface === self::SURFACE
            && $enrollment->recipient instanceof Contact
            && $enrollment->context instanceof CampaignEnrollment
            && $enrollment->origin instanceof Campaign;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(MessageChainEnrollment $enrollment): array
    {
        $enrollment->loadMissing(['recipient', 'context', 'origin']);

        $contact = $enrollment->recipient;
        $campaignEnrollment = $enrollment->context;
        $campaign = $enrollment->origin;

        if (! $contact instanceof Contact
            || ! $campaignEnrollment instanceof CampaignEnrollment
            || ! $campaign instanceof Campaign
        ) {
            return [];
        }

        if ((int) $campaignEnrollment->campaign_id !== (int) $campaign->getKey()) {
            throw new RuntimeException(sprintf(
                'Campaign MessageChainEnrollment [%d] has conflicting Campaign origin/context identity.',
                (int) $enrollment->getKey(),
            ));
        }

        $startContext = is_array($campaignEnrollment->start_context)
            ? $campaignEnrollment->start_context
            : [];
        $payload = is_array($startContext['payload'] ?? null)
            ? $startContext['payload']
            : [];

        $values = array_replace_recursive(
            $startContext,
            $payload,
        );

        // Canonical persisted models always win over caller-provided payload keys.
        // Keep both generic and Campaign-specific aliases so timing/conditions can
        // migrate without reaching across module boundaries.
        return array_replace_recursive($values, [
            'recipient' => $this->modelValues($contact),
            'contact' => $this->modelValues($contact),
            'context' => $this->modelValues($campaignEnrollment),
            'campaign_enrollment' => $this->modelValues($campaignEnrollment),
            'origin' => $this->modelValues($campaign),
            'campaign' => $this->modelValues($campaign),
        ]);
    }

    /** @return array<string, mixed> */
    private function modelValues(Model $model): array
    {
        return $model->attributesToArray();
    }
}