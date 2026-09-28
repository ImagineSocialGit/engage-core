<?php

namespace App\Modules\Campaigns\Contacts;

use App\Modules\Campaigns\Access\CampaignsAccessCapabilityContributor;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Contracts\Contacts\ContactResultActionContributor;
use App\Modules\Core\Data\Contacts\ContactResultAction;
use Illuminate\Support\Facades\Schema;

final class CampaignContactResultActionContributor implements ContactResultActionContributor
{
    public function actions(): iterable
    {
        $plural = str((string) config('contacts.labels.plural', 'contacts'))
            ->lower()
            ->toString();

        $campaigns = Schema::hasTable('campaigns')
            ? Campaign::query()
                ->with('messageChain.currentVersion.steps')
                ->where('status', Campaign::STATUS_ACTIVE)
                ->orderBy('name')
                ->get(['id', 'key', 'name', 'execution_strategy', 'message_chain_id'])
                ->map(fn (Campaign $campaign): array => [
                    'value' => (string) $campaign->key,
                    'label' => (string) $campaign->name,
                    'strategy' => (string) $campaign->execution_strategy,
                    'messages' => $this->messageOptions($campaign),
                ])
                ->values()
                ->all()
            : [];

        yield new ContactResultAction(
            key: 'campaigns.enroll',
            label: 'Enroll in campaign',
            description: 'Enroll, restart, or manage allocation-message exclusions for this visible '.$plural.' result set.',
            view: 'crm.campaigns.partials.contact-result-action',
            capability: CampaignsAccessCapabilityContributor::ENROLL_CONTACT_RESULTS,
            sort: 20,
            data: [
                'campaigns' => $campaigns,
            ],
            groupKey: 'messaging',
            groupLabel: 'Messaging',
            groupDescription: 'Send or enroll this result set using the messaging tools available to this client.',
            groupSort: 20,
        );
    }

    /** @return array<int, array{value: string, label: string}> */
    private function messageOptions(Campaign $campaign): array
    {
        $version = $campaign->messageChain?->currentVersion;

        if ($version === null || ! $version->isPublished()) {
            return [];
        }

        return $version->steps
            ->filter(static fn ($step): bool => (bool) $step->is_active)
            ->values()
            ->map(static fn ($step, int $index): array => [
                'value' => (string) $step->key,
                'label' => trim((string) $step->name) !== ''
                    ? (string) $step->name
                    : 'Message '.($index + 1),
            ])
            ->all();
    }
}