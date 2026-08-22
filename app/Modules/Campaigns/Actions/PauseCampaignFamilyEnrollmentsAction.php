<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PauseCampaignFamilyEnrollmentsAction
{
    public function __construct(
        private readonly PauseCampaignEnrollmentAction $pauseCampaignEnrollment,
    ) {}

    /**
     * @param array<string, mixed>|null $meta
     * @return Collection<int, CampaignEnrollment>
     */
    public function handle(
        Contact $contact,
        string $familyKey,
        ?Model $source = null,
        ?string $reason = null,
        bool $skipPendingMessages = true,
        ?array $meta = null,
    ): Collection {
        $familyKey = trim($familyKey);

        if ($familyKey === '') {
            throw new InvalidArgumentException('Campaign family key cannot be empty.');
        }

        return DB::transaction(function () use (
            $contact,
            $familyKey,
            $source,
            $reason,
            $skipPendingMessages,
            $meta,
        ): Collection {
            $campaignIds = Campaign::query()
                ->where('family_key', $familyKey)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($campaignIds === []) {
                return new Collection();
            }

            $enrollments = CampaignEnrollment::query()
                ->with(['campaign', 'messageChainEnrollment'])
                ->where('contact_id', $contact->getKey())
                ->whereIn('campaign_id', $campaignIds)
                ->whereNotNull('message_chain_enrollment_id')
                ->whereHas(
                    'messageChainEnrollment',
                    fn ($query) => $query->whereIn('status', [
                        MessageChainEnrollment::STATUS_ACTIVE,
                        MessageChainEnrollment::STATUS_PAUSED,
                    ]),
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $paused = new Collection();

            foreach ($enrollments as $enrollment) {
                $paused->push($this->pauseCampaignEnrollment->pauseEnrollment(
                    enrollment: $enrollment,
                    source: $source,
                    reason: $reason,
                    skipPendingMessages: $skipPendingMessages,
                    meta: $meta,
                ));
            }

            return $paused;
        }, 3);
    }
}