<?php

namespace App\Modules\Messaging\Import;

use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessor;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Messaging\Actions\ImportMessageConsentAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\Consent\MessageConsentStateResolver;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use InvalidArgumentException;

final class MarketingPermissionContactImportPostProcessor implements ContactImportPostProcessor
{
    private const SCOPE_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    public function __construct(
        private readonly ImportMessageConsentAction $importMessageConsent,
        private readonly MessageConsentStateResolver $consentState,
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
    ) {}

    public function key(): string
    {
        return 'marketing_permission';
    }

    public function label(): string
    {
        return 'Marketing permission';
    }

    public function sort(): int
    {
        return 100;
    }

    public function normalizeConfig(array $config): array
    {
        $unknown = array_values(array_diff(array_keys($config), ['channels', 'scope']));

        if ($unknown !== []) {
            sort($unknown);

            throw new InvalidArgumentException(sprintf(
                'Contact import marketing permission contains unknown field(s): %s.',
                implode(', ', $unknown),
            ));
        }

        $channels = $config['channels'] ?? null;

        if (! is_array($channels) || ! array_is_list($channels) || $channels === []) {
            throw new InvalidArgumentException(
                'Contact import marketing permission [channels] must be a non-empty list.',
            );
        }

        $allowed = [
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ];
        $normalizedChannels = [];

        foreach ($channels as $channel) {
            if (! is_string($channel)) {
                throw new InvalidArgumentException(
                    'Contact import marketing permission channels must be strings.',
                );
            }

            $channel = str_replace('-', '_', strtolower(trim($channel)));

            if (! in_array($channel, $allowed, true)) {
                throw new InvalidArgumentException(
                    "Unsupported contact import marketing channel [{$channel}].",
                );
            }

            $normalizedChannels[] = $channel;
        }

        $scope = $config['scope'] ?? null;

        if (! is_string($scope) || ! preg_match(self::SCOPE_PATTERN, trim($scope))) {
            throw new InvalidArgumentException(
                'Contact import marketing permission [scope] must be a lowercase snake_case key.',
            );
        }

        return [
            'channels' => array_values(array_unique($normalizedChannels)),
            'scope' => trim($scope),
        ];
    }

    public function summary(array $config): string
    {
        $config = $this->normalizeConfig($config);
        $channels = array_map(
            static fn (string $channel): string => strtoupper($channel),
            $config['channels'],
        );

        return sprintf(
            'Import %s marketing permission; retain capture scope [%s].',
            implode(' + ', $channels),
            $config['scope'],
        );
    }

    public function handle(
        ContactImportContext $context,
        array $config,
    ): ContactImportPostProcessResult {
        $config = $this->normalizeConfig($config);
        $channels = [];
        $applied = 0;
        $skipped = 0;
        $revoked = 0;

        foreach ($config['channels'] as $channel) {
            $destinationState = $this->destinationState($context, $channel);

            if ($destinationState !== 'available') {
                $channels[$channel] = [
                    'state' => 'skipped',
                    'reason_code' => $destinationState,
                ];
                $skipped++;
                continue;
            }

            $activeConsent = $this->consentState->activeConsent(
                contact: $context->contact,
                channel: $channel,
                purpose: MessagePurpose::Marketing,
            );

            if ($activeConsent !== null) {
                $channels[$channel] = [
                    'state' => 'reused',
                    'consent_id' => (int) $activeConsent->getKey(),
                    'scope' => $activeConsent->scope,
                ];
                $applied++;
                continue;
            }

            $latestRevocation = $this->consentState->latestRevocation(
                contact: $context->contact,
                channel: $channel,
                purpose: MessagePurpose::Marketing,
            );
            $latestConsent = $this->consentState->latestConsent(
                contact: $context->contact,
                channel: $channel,
                purpose: MessagePurpose::Marketing,
            );

            if ($latestRevocation !== null
                && ($latestConsent === null
                    || $latestRevocation->revoked_at->greaterThanOrEqualTo($latestConsent->consented_at))
            ) {
                $channels[$channel] = [
                    'state' => 'skipped',
                    'reason_code' => 'marketing_permission_revoked',
                    'revocation_id' => (int) $latestRevocation->getKey(),
                ];
                $skipped++;
                $revoked++;
                continue;
            }

            $result = $this->importMessageConsent->handle(
                contact: $context->contact,
                channel: $channel,
                purpose: MessagePurpose::Marketing->value,
                scope: $config['scope'],
                consentedAt: $context->batch->imported_at ?? now(),
                source: 'contact_import',
                meta: [
                    'contact_import_batch_id' => $context->batch->getKey(),
                    'contact_import_occurrence_id' => $context->occurrence->getKey(),
                    'contact_import_profile_key' => $context->profileKey,
                ],
            );

            $channels[$channel] = [
                'state' => $result['created'] ? 'granted' : 'reused',
                'consent_id' => (int) $result['consent']->getKey(),
                'scope' => $result['consent']->scope,
            ];
            $applied++;
        }

        $meta = [
            'purpose' => MessagePurpose::Marketing->value,
            'capture_scope' => $config['scope'],
            'channels' => $channels,
        ];

        if ($applied === 0) {
            return ContactImportPostProcessResult::skipped(
                reasonCode: $revoked > 0
                    ? 'marketing_permission_revoked'
                    : 'marketing_destination_unavailable',
                message: $revoked > 0
                    ? 'Marketing permission was not re-imported because at least one configured channel is currently revoked.'
                    : 'Marketing permission could not be imported because no configured channel had a usable destination.',
                meta: $meta,
            );
        }

        if ($skipped > 0) {
            return ContactImportPostProcessResult::partial(
                reasonCode: 'marketing_permission_partially_applied',
                message: 'Marketing permission was imported for available channels; at least one configured channel lacked a usable destination.',
                meta: $meta,
            );
        }

        return ContactImportPostProcessResult::applied(
            meta: $meta,
            message: 'Configured marketing permission was imported.',
        );
    }

    private function destinationState(ContactImportContext $context, string $channel): string
    {
        if ($channel === MessageChannel::Email->value) {
            return is_string($context->contact->email) && trim($context->contact->email) !== ''
                ? 'available'
                : 'email_destination_missing';
        }

        $phone = is_string($context->contact->phone)
            ? trim($context->contact->phone)
            : '';

        if ($phone === '') {
            return 'sms_destination_missing';
        }

        try {
            $normalized = $this->phoneNumberNormalizer->normalize($phone);
        } catch (InvalidArgumentException) {
            $normalized = null;
        }

        return $normalized !== null
            ? 'available'
            : 'sms_destination_invalid';
    }
}