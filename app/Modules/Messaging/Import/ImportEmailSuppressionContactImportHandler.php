<?php

namespace App\Modules\Messaging\Import;

use App\Modules\Core\Contracts\Contacts\ContactImportHandler;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Messaging\Services\MessageSuppressionService;

/**
 * Provider-neutral external email hygiene evidence. This is independent of
 * consent: imported signup timestamps never grant permission.
 */
final class ImportEmailSuppressionContactImportHandler implements ContactImportHandler
{
    public function __construct(
        private readonly MessageSuppressionService $suppressions,
        private readonly SkipScheduledMessagesAction $skipScheduledMessages,
    ) {}

    public function handle(ContactImportContext $context): void
    {
        $externalStatus = $context->value('external_email_status');
        $status = $externalStatus !== null
            ? str_replace([' ', '-'], '_', strtolower(trim($externalStatus)))
            : null;
        $cleanedAt = $context->value('email_cleaned_at');
        $campaignId = $context->value('email_clean_campaign_id');
        $campaignTitle = $context->value('email_clean_campaign_title');

        $reason = match ($status) {
            'cleaned', 'bounce', 'bounced', 'hard_bounce', 'repeated_soft_bounce' => MessageSuppression::REASON_BOUNCE,
            'complaint', 'complained', 'spam_complaint' => MessageSuppression::REASON_COMPLAINT,
            'invalid', 'invalid_destination' => MessageSuppression::REASON_INVALID_DESTINATION,
            'suppressed', 'provider_suppressed', 'blocked' => MessageSuppression::REASON_PROVIDER,
            default => null,
        };

        // An explicitly mapped cleanup timestamp is independent bounce evidence.
        // Do not interpret arbitrary values as dates or suppress unknown statuses.
        if ($reason === null && $status === null && $cleanedAt !== null
            && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]|$)/D', $cleanedAt)
        ) {
            $reason = MessageSuppression::REASON_BOUNCE;
        }

        if ($externalStatus === null && $cleanedAt === null && $campaignId === null && $campaignTitle === null) {
            return;
        }

        $meta = is_array($context->occurrence->meta) ? $context->occurrence->meta : [];
        $meta['external_email_delivery'] = [
            'status_raw' => $externalStatus,
            'cleaned_at_raw' => $cleanedAt,
            'campaign_id' => $campaignId,
            'campaign_title' => $campaignTitle,
            'suppression_reason' => $reason,
        ];
        $context->occurrence->forceFill(['meta' => $meta])->save();

        if ($reason === null) {
            return;
        }

        $email = $context->contact->email;

        if (! is_string($email) || trim($email) === '') {
            return;
        }

        $this->suppressions->suppress(
            channel: MessageChannel::Email,
            destination: strtolower(trim($email)),
            reason: $reason,
            sourceEventId: 'contact-import-occurrence:'.$context->occurrence->getKey(),
            meta: [
                'origin' => 'contact_import',
                'batch_id' => $context->batch->getKey(),
                'occurrence_id' => $context->occurrence->getKey(),
                'profile_key' => $context->profileKey,
                'external_status' => $externalStatus,
                'external_cleaned_at' => $cleanedAt,
                'external_campaign_id' => $campaignId,
                'external_campaign_title' => $campaignTitle,
            ],
        );

        $this->skipScheduledMessages->forRecipientChannel(
            recipient: $context->contact,
            channel: MessageChannel::Email->value,
            reason: 'destination_suppressed_'.$reason,
        );
    }
}