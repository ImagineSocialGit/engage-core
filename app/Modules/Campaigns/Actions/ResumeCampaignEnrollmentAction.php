<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ResumeMessageChainEnrollmentAction;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ResumeCampaignEnrollmentAction
{
    public const DEFAULT_REASON = 'campaign_resumed';

    public function __construct(
        private readonly ResumeMessageChainEnrollmentAction $resumeMessageChainEnrollment,
    ) {}

    /** @param array<string, mixed>|null $meta */
    public function handle(
        Contact $contact,
        string $campaignKey,
        ?Model $source = null,
        ?string $reason = null,
        ?array $meta = null,
    ): ?CampaignEnrollment {
        $enrollment = CampaignEnrollment::query()
            ->with(['campaign', 'messageChainEnrollment'])
            ->where('contact_id', $contact->getKey())
            ->where('campaign_key', $campaignKey)
            ->whereNotNull('message_chain_enrollment_id')
            ->whereHas(
                'messageChainEnrollment',
                fn ($query) => $query->whereIn('status', [
                    MessageChainEnrollment::STATUS_ACTIVE,
                    MessageChainEnrollment::STATUS_PAUSED,
                ]),
            )
            ->orderByDesc('id')
            ->first();

        if (! $enrollment instanceof CampaignEnrollment) {
            return null;
        }

        return $this->resumeEnrollment($enrollment, $source, $reason, $meta);
    }

    /** @param array<string, mixed>|null $meta */
    public function resumeEnrollment(
        CampaignEnrollment $enrollment,
        ?Model $source = null,
        ?string $reason = null,
        ?array $meta = null,
    ): CampaignEnrollment {
        $enrollmentId = (int) $enrollment->getKey();
        $campaignId = is_numeric($enrollment->campaign_id) ? (int) $enrollment->campaign_id : null;
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($enrollmentId, $campaignId, $source, $reason, $meta): CampaignEnrollment {
            $campaign = $campaignId !== null
                ? Campaign::query()->whereKey($campaignId)->lockForUpdate()->first()
                : null;

            if ($campaign instanceof Campaign && ! $campaign->isActive()) {
                throw new InvalidArgumentException(sprintf(
                    'Campaign [%s] must be active before enrollment [%d] can be resumed.',
                    (string) $campaign->key,
                    $enrollmentId,
                ));
            }

            $lockedEnrollment = CampaignEnrollment::query()
                ->with('messageChainEnrollment')
                ->whereKey($enrollmentId)
                ->lockForUpdate()
                ->firstOrFail();
            $chainEnrollment = $lockedEnrollment->messageChainEnrollment;

            if (! $chainEnrollment instanceof MessageChainEnrollment) {
                throw new RuntimeException(sprintf(
                    'CampaignEnrollment [%d] cannot be resumed because it has no linked MessageChainEnrollment.',
                    $enrollmentId,
                ));
            }

            if ($chainEnrollment->isTerminal() || $chainEnrollment->status === MessageChainEnrollment::STATUS_ACTIVE) {
                return $lockedEnrollment;
            }

            $resumed = $this->resumeMessageChainEnrollment->handle($chainEnrollment);

            if ($resumed->status !== MessageChainEnrollment::STATUS_ACTIVE) {
                return $lockedEnrollment;
            }

            $existingMeta = is_array($lockedEnrollment->meta) ? $lockedEnrollment->meta : [];
            $lockedEnrollment->forceFill([
                'meta' => array_replace_recursive($existingMeta, [
                    'lifecycle' => [
                        'last_resume' => [
                            'reason' => $reason,
                            'source_type' => $source?->getMorphClass(),
                            'source_id' => $source?->getKey(),
                            'meta' => $meta ?? [],
                            'resumed_at' => $resumed->resumed_at?->toISOString(),
                        ],
                    ],
                ]),
            ])->save();
            $lockedEnrollment->setRelation('messageChainEnrollment', $resumed);

            return $lockedEnrollment->refresh()->load('messageChainEnrollment');
        }, 3);
    }

    private function reason(?string $reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';

        return mb_substr($reason !== '' ? $reason : self::DEFAULT_REASON, 0, 96);
    }
}