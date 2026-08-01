<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryComponent;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageComponent;
use App\Modules\Messaging\Support\EmailConsentRevocationLinkGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AttachScheduledMessageComponentsAction
{
    public function __construct(
        private readonly EmailConsentRevocationLinkGenerator $revocationLinks,
    ) {}

    /**
     * @param array<int, MessageDeliveryComponent> $components
     * @return array<int, string>
     */
    public function handle(
        ScheduledMessage|int $scheduledMessage,
        array $components,
    ): array {
        $messageId = $scheduledMessage instanceof ScheduledMessage
            ? (int) $scheduledMessage->getKey()
            : $scheduledMessage;

        return DB::transaction(function () use ($messageId, $components): array {
            $message = ScheduledMessage::query()
                ->with(['components', 'renderContext', 'recipient'])
                ->whereKey($messageId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($message->status !== ScheduledMessage::STATUS_PENDING
                || $message->renderContext !== null
            ) {
                return [];
            }

            $eligible = collect($components)
                ->filter(fn (mixed $component): bool =>
                    $component instanceof MessageDeliveryComponent
                    && $component->channel === $message->channel
                )
                ->sortBy('sortOrder')
                ->values();

            if ($eligible->isEmpty()) {
                return [];
            }

            $covered = $message->components
                ->pluck('intent_key')
                ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
                ->values()
                ->all();
            $nextSortOrder = max(
                0,
                (int) $message->components->max('sort_order'),
            );

            foreach ($eligible as $component) {
                if (in_array($component->intentKey, $covered, true)) {
                    continue;
                }

                $version = MessageTemplateVersion::query()
                    ->with('messageTemplate')
                    ->findOrFail($component->messageTemplateVersionId);

                if ($version->messageTemplate?->channel !== $message->channel) {
                    throw new RuntimeException(
                        "Scheduled message component [{$component->intentKey}] channel does not match its carrier.",
                    );
                }

                $nextSortOrder = max(
                    $nextSortOrder + 10,
                    $component->sortOrder,
                );

                ScheduledMessageComponent::query()->create([
                    'scheduled_message_id' => $message->getKey(),
                    'message_template_version_id' => $version->getKey(),
                    'role' => $component->role,
                    'intent_key' => $component->intentKey,
                    'message_consent_id' => $component->messageConsentId,
                    'sort_order' => $nextSortOrder,
                    'placement_key' => $component->placementKey,
                ]);

                $covered[] = $component->intentKey;
            }

            $covered = array_values(array_unique($covered));

            if ($this->needsMarketingUnsubscribe($message, $covered)) {
                $recipient = $message->recipient;

                if ($recipient instanceof Contact) {
                    $payload = is_array($message->payload)
                        ? $message->payload
                        : [];
                    $payload['unsubscribe_url'] = $this->revocationLinks
                        ->marketingUnsubscribeUrl($recipient);
                    $message->forceFill(['payload' => $payload])->save();
                }
            }

            return $covered;
        }, 3);
    }

    /**
     * @param array<int, string> $covered
     */
    private function needsMarketingUnsubscribe(
        ScheduledMessage $message,
        array $covered,
    ): bool {
        return $message->channel === 'email'
            && in_array(
                'consent.marketing.email.acknowledgement',
                $covered,
                true,
            );
    }
}