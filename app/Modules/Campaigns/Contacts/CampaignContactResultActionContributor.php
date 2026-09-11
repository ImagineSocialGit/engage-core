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
                ->where('status', Campaign::STATUS_ACTIVE)
                ->orderBy('name')
                ->get(['key', 'name'])
                ->map(static fn (Campaign $campaign): array => [
                    'value' => (string) $campaign->key,
                    'label' => (string) $campaign->name,
                ])
                ->values()
                ->all()
            : [];

        yield new ContactResultAction(
            key: 'campaigns.enroll',
            label: 'Enroll in campaign',
            description: 'Choose an active Campaign and enroll this visible '.$plural.' result set.',
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
}