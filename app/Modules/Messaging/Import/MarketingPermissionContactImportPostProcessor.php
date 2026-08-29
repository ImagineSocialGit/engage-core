<?php

namespace App\Modules\Messaging\Import;

use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessor;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessorOperatorConfigProvider;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Messaging\Actions\ImportMessageConsentAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\Consent\MessageConsentStateResolver;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class MarketingPermissionContactImportPostProcessor implements
    ContactImportPostProcessor,
    ContactImportPostProcessorOperatorConfigProvider
{
    private const SCOPE_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    private const DECISION_PENDING = 'pending';
    private const DECISION_CONFIRMED = 'confirmed';
    private const DECISION_NOT_CONFIRMED = 'not_confirmed';

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
        $unknown = array_values(array_diff(
            array_keys($config),
            ['channels', 'scope', 'operator_decision', 'attested'],
        ));

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

        $operatorDecision = $config['operator_decision'] ?? null;

        if ($operatorDecision !== null) {
            if (! is_string($operatorDecision)) {
                throw new InvalidArgumentException(
                    'Contact import marketing permission [operator_decision] must be a string when supplied.',
                );
            }

            $operatorDecision = str_replace('-', '_', strtolower(trim($operatorDecision)));

            if (! in_array($operatorDecision, [
                self::DECISION_PENDING,
                self::DECISION_CONFIRMED,
                self::DECISION_NOT_CONFIRMED,
            ], true)) {
                throw new InvalidArgumentException(
                    "Unsupported contact import marketing permission decision [{$operatorDecision}].",
                );
            }
        }

        return [
            'channels' => array_values(array_unique($normalizedChannels)),
            'scope' => trim($scope),
            'operator_decision' => $operatorDecision,
            'attested' => (bool) ($config['attested'] ?? false),
        ];
    }

    public function operatorConfig(?array $configured): array
    {
        $base = $configured ?? [
            'channels' => [
                MessageChannel::Email->value,
                MessageChannel::Sms->value,
            ],
            'scope' => 'contact_import',
        ];
        $base = $this->normalizeConfig($base);

        return [
            ...$base,
            'operator_decision' => self::DECISION_PENDING,
            'attested' => false,
        ];
    }

    public function shouldProcess(array $config): bool
    {
        $config = $this->normalizeConfig($config);

        if ($config['operator_decision'] === null) {
            return true;
        }

        return $config['operator_decision'] === self::DECISION_CONFIRMED
            && $config['attested'] === true
            && $config['channels'] !== [];
    }

    public function summary(array $config): string
    {
        $config = $this->normalizeConfig($config);

        if ($config['operator_decision'] === self::DECISION_PENDING) {
            return 'Confirm whether these contacts already granted marketing permission before this import.';
        }

        if ($config['operator_decision'] === self::DECISION_NOT_CONFIRMED) {
            return 'Import contacts without marketing permission.';
        }

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

    public function inputDefinitions(array $config): array
    {
        $config = $this->normalizeConfig($config);
        $channelOptions = [];

        foreach ($config['channels'] as $channel) {
            $channelOptions[] = [
                'value' => $channel,
                'label' => $channel === MessageChannel::Email->value
                    ? 'Email marketing'
                    : 'SMS marketing',
            ];
        }

        return [
            [
                'key' => 'permission_status',
                'label' => 'Have these contacts already given permission to receive marketing messages?',
                'type' => 'select',
                'required' => true,
                'full_width' => true,
                'description' => 'Only confirm permission that was already collected through another platform or process.',
                'options' => [
                    [
                        'value' => self::DECISION_CONFIRMED,
                        'label' => 'Yes — permission was already collected elsewhere',
                    ],
                    [
                        'value' => self::DECISION_NOT_CONFIRMED,
                        'label' => 'No / I’m not sure',
                    ],
                ],
            ],
            [
                'key' => 'channels',
                'label' => 'Permission already exists for',
                'type' => 'checkbox_group',
                'required' => true,
                'options' => $channelOptions,
                'show_when' => [
                    'field' => 'permission_status',
                    'equals' => self::DECISION_CONFIRMED,
                ],
            ],
            [
                'key' => 'attestation',
                'label' => 'I confirm these contacts previously agreed to receive marketing through the selected channels.',
                'type' => 'checkbox',
                'required' => true,
                'full_width' => true,
                'description' => 'This records imported permission; it does not send a permission request.',
                'show_when' => [
                    'field' => 'permission_status',
                    'equals' => self::DECISION_CONFIRMED,
                ],
            ],
        ];
    }

    public function withSubmittedInputs(
        array $config,
        array $submitted,
    ): array {
        $config = $this->normalizeConfig($config);
        $unknown = array_values(array_diff(
            array_keys($submitted),
            ['permission_status', 'channels', 'attestation'],
        ));

        if ($unknown !== []) {
            sort($unknown);

            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}" => 'Marketing permission received unsupported operator input.',
            ]);
        }

        $decision = $submitted['permission_status'] ?? null;

        if (! is_string($decision) || trim($decision) === '') {
            $decision = self::DECISION_NOT_CONFIRMED;
        } else {
            $decision = str_replace('-', '_', strtolower(trim($decision)));
        }

        if (! in_array($decision, [
            self::DECISION_CONFIRMED,
            self::DECISION_NOT_CONFIRMED,
        ], true)) {
            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}.permission_status" => 'Choose a valid marketing permission option.',
            ]);
        }

        if ($decision === self::DECISION_NOT_CONFIRMED) {
            return [
                ...$config,
                'operator_decision' => self::DECISION_NOT_CONFIRMED,
                'attested' => false,
            ];
        }

        $submittedChannels = $submitted['channels'] ?? [];

        if (! is_array($submittedChannels) || ! array_is_list($submittedChannels)) {
            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}.channels" => 'Choose the marketing channels that already have permission.',
            ]);
        }

        $selectedChannels = [];

        foreach ($submittedChannels as $channel) {
            if (! is_string($channel)) {
                continue;
            }

            $channel = str_replace('-', '_', strtolower(trim($channel)));

            if (! in_array($channel, $config['channels'], true)) {
                throw ValidationException::withMessages([
                    "post_import_inputs.{$this->key()}.channels" => 'A selected marketing channel is not available for this import.',
                ]);
            }

            $selectedChannels[] = $channel;
        }

        $selectedChannels = array_values(array_unique($selectedChannels));

        if ($selectedChannels === []) {
            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}.channels" => 'Choose at least one channel with existing marketing permission.',
            ]);
        }

        $attested = in_array(
            $submitted['attestation'] ?? null,
            [true, 1, '1', 'on', 'yes'],
            true,
        );

        if (! $attested) {
            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}.attestation" => 'Confirm that the selected marketing permission was previously granted.',
            ]);
        }

        return [
            ...$config,
            'channels' => $selectedChannels,
            'operator_decision' => self::DECISION_CONFIRMED,
            'attested' => true,
        ];
    }

    public function handle(
        ContactImportContext $context,
        array $config,
    ): ContactImportPostProcessResult {
        $config = $this->normalizeConfig($config);

        if (! $this->shouldProcess($config)) {
            return ContactImportPostProcessResult::skipped(
                reasonCode: 'marketing_permission_not_confirmed',
                message: 'Marketing permission was not imported because prior permission was not confirmed.',
            );
        }

        $channels = [];
        $applied = 0;
        $skipped = 0;
        $revoked = 0;
        $evidence = $config['operator_decision'] === self::DECISION_CONFIRMED
            ? 'operator_attested_existing_consent'
            : 'server_configured_import_permission';

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
                    'permission_evidence' => $evidence,
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
            'permission_evidence' => $evidence,
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
            message: 'Confirmed marketing permission was imported.',
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