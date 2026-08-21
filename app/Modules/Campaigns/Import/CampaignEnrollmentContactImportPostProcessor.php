<?php

namespace App\Modules\Campaigns\Import;

use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Exceptions\CampaignUnavailableForEnrollmentException;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessor;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use InvalidArgumentException;

final class CampaignEnrollmentContactImportPostProcessor implements ContactImportPostProcessor
{
    private const KEY_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    public function __construct(
        private readonly EnrollContactInCampaignAction $enrollContactInCampaign,
    ) {}

    public function key(): string
    {
        return 'campaign_enrollment';
    }

    public function label(): string
    {
        return 'Campaign enrollment';
    }

    public function sort(): int
    {
        return 200;
    }

    public function normalizeConfig(array $config): array
    {
        $unknown = array_values(array_diff(array_keys($config), ['campaign_key']));

        if ($unknown !== []) {
            sort($unknown);

            throw new InvalidArgumentException(sprintf(
                'Contact import Campaign enrollment contains unknown field(s): %s.',
                implode(', ', $unknown),
            ));
        }

        $campaignKey = $config['campaign_key'] ?? null;

        if (! is_string($campaignKey) || ! preg_match(self::KEY_PATTERN, trim($campaignKey))) {
            throw new InvalidArgumentException(
                'Contact import Campaign enrollment [campaign_key] must be a lowercase snake_case key.',
            );
        }

        return [
            'campaign_key' => trim($campaignKey),
        ];
    }

    public function summary(array $config): string
    {
        $config = $this->normalizeConfig($config);

        return "Enroll each successful Contact in Campaign [{$config['campaign_key']}].";
    }

    public function handle(
        ContactImportContext $context,
        array $config,
    ): ContactImportPostProcessResult {
        $config = $this->normalizeConfig($config);

        try {
            $enrollment = $this->enrollContactInCampaign->handle(
                contact: $context->contact,
                campaignKey: $config['campaign_key'],
                source: $context->occurrence,
                meta: [
                    'contact_import' => [
                        'batch_id' => (int) $context->batch->getKey(),
                        'occurrence_id' => (int) $context->occurrence->getKey(),
                        'profile_key' => $context->profileKey,
                    ],
                ],
                entryKey: $this->entryKey($context, $config['campaign_key']),
                eagerProcess: false,
                startContext: [
                    'source' => 'contact_import',
                    'contact_import_batch_id' => (int) $context->batch->getKey(),
                    'contact_import_occurrence_id' => (int) $context->occurrence->getKey(),
                    'contact_import_profile_key' => $context->profileKey,
                ],
            );
        } catch (CampaignUnavailableForEnrollmentException $exception) {
            if ($exception->reason === CampaignUnavailableForEnrollmentException::REASON_FAMILY_BLOCKED) {
                return ContactImportPostProcessResult::blocked(
                    reasonCode: $exception->reason,
                    message: $exception->getMessage(),
                    meta: [
                        'campaign_key' => $config['campaign_key'],
                        'family_key' => $exception->familyKey,
                        'campaign_priority' => $exception->campaignPriority,
                        'blocking_campaign_key' => $exception->blockingCampaignKey,
                        'blocking_priority' => $exception->blockingPriority,
                        'blocking_enrollment_id' => $exception->blockingEnrollmentId,
                    ],
                );
            }

            return ContactImportPostProcessResult::failed(
                reasonCode: $exception->reason,
                message: $exception->getMessage(),
                meta: [
                    'campaign_key' => $config['campaign_key'],
                    'campaign_status' => $exception->campaignStatus,
                ],
            );
        }

        return ContactImportPostProcessResult::applied(
            meta: [
                'campaign_key' => $config['campaign_key'],
                'campaign_enrollment_id' => (int) $enrollment->getKey(),
                'message_chain_enrollment_id' => is_numeric($enrollment->message_chain_enrollment_id)
                    ? (int) $enrollment->message_chain_enrollment_id
                    : null,
            ],
            message: 'Campaign enrollment is active or was already open.',
        );
    }

    private function entryKey(ContactImportContext $context, string $campaignKey): string
    {
        $profileKey = is_string($context->profileKey) && trim($context->profileKey) !== ''
            ? trim($context->profileKey)
            : 'batch_'.(int) $context->batch->getKey();

        return implode(':', [
            'contact_import',
            $profileKey,
            $campaignKey,
        ]);
    }
}