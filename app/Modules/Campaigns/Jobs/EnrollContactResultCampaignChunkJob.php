<?php

namespace App\Modules\Campaigns\Jobs;

use App\Models\User;
use App\Modules\Campaigns\Access\CampaignsAccessCapabilityContributor;
use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class EnrollContactResultCampaignChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param array<int, int> $contactIds
     */
    public function __construct(
        public readonly string $campaignKey,
        public readonly array $contactIds,
        public readonly string $operationId,
        public readonly int $actorUserId,
    ) {}

    public function handle(
        EnrollContactInCampaignAction $enroll,
        UserAccessService $access,
        ContactVisibility $visibility,
    ): void {
        $actor = User::query()->find($this->actorUserId);

        if (! $actor instanceof User || ! $access->allows(
            $actor,
            CampaignsAccessCapabilityContributor::ENROLL_CONTACT_RESULTS,
        )) {
            return;
        }

        $contacts = $visibility
            ->apply(
                Contact::query()->whereIn('contacts.id', $this->contactIds),
                $actor,
            )
            ->reorder()
            ->orderBy('contacts.id')
            ->get();

        foreach ($contacts as $contact) {
            $enroll->handle(
                contact: $contact,
                campaignKey: $this->campaignKey,
                meta: [
                    'source' => 'contact_result_action',
                    'actor_user_id' => $this->actorUserId,
                    'operation_id' => $this->operationId,
                ],
                startContext: [
                    'source' => 'contact_result_action',
                    'actor_user_id' => $this->actorUserId,
                ],
                entryKey: 'contact_result_action:'.$this->operationId,
                eagerProcess: true,
            );
        }
    }
}