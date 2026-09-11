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

final class ServiceRelationshipPermissionContactImportPostProcessor implements
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
        return 'service_relationship_permission';
    }

    public function label(): string
    {
        return 'Existing relationship / service messaging';
    }

    public function sort(): int
    {
        return 90;
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
                'Contact import service relationship permission contains unknown field(s): %s.',
                implode(', ', $unknown),
            ));
        }

        $channels = $config['channels'] ?? null;

        if (! is_array($channels) || ! array_is_list($channels) || $channels === []) {
            throw new InvalidArgumentException(
                'Contact import service relationship permission [channels] must be a non-empty list.',
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
                    'Contact import service relationship permission channels must be strings.',
                );
            }

            $channel = str_replace('-', '_', strtolower(trim($channel)));

            if (! in_array($channel, $allowed, true)) {
                throw new InvalidArgumentException(
                    "Unsupported contact import service relationship channel [{$channel}].",
                );
            }

            $normalizedChannels[] = $channel;
        }

        $scope = $config['scope'] ?? null;

        if (! is_string($scope) || ! preg_match(self::SCOPE_PATTERN, trim($scope))) {
            throw new InvalidArgumentException(
                'Contact import service relationship permission [scope] must be a lowercase snake_case key.',
            );
        }

        $operatorDecision = $config['operator_decision'] ?? null;

        if ($operatorDecision !== null) {
            if (! is_string($operatorDecision)) {
                throw new InvalidArgumentException(
                    'Contact import service relationship permission [operator_decision] must be a string when supplied.',
                );
            }

            $operatorDecision = str_replace('-', '_', strtolower(trim($operatorDecision)));

            if (! in_array($operatorDecision, [
                self::DECISION_PENDING,
                self::DECISION_CONFIRMED,
                self::DECISION_NOT_CONFIRMED,
            ], true)) {
                throw new InvalidArgumentException(
                    "Unsupported contact import service relationship permission decision [{$operatorDecision}].",
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
            return 'Confirm whether these contacts already have an existing relationship or active inquiry that supports normal one-to-one follow-up.';
        }

        if ($config['operator_decision'] === self::DECISION_NOT_CONFIRMED) {
            return 'Import contacts without service-message permission.';
        }

        $channels = array_map(
            static fn (string $channel): string => strtoupper($channel),
            $config['channels'],
        );

        return sprintf(
            'Import %s service-message permission from an existing relationship; retain capture scope [%s].',
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
                    ? 'Email service follow-up'
                    : 'SMS service follow-up',
            ];
        }

        return [
            [
                'key' => 'relationship_status',
                'label' => 'Do these contacts already have an existing relationship or active inquiry that supports normal one-to-one follow-up?',
                'type' => 'select',
                'required' => true,
                'full_width' => true,
                'description' => 'Examples include existing customers, prior clients, people who contacted you, or people you are actively helping. This is separate from marketing permission.',
                'options' => [
                    [
                        'value' => self::DECISION_CONFIRMED,
                        'label' => 'Yes — normal service/business follow-up is expected',
                    ],
                    [
                        'value' => self::DECISION_NOT_CONFIRMED,
                        'label' => 'No / I’m not sure',
                    ],
                ],
            ],
            [
                'key' => 'channels',
                'label' => 'Existing relationship supports follow-up through',
                'type' => 'checkbox_group',
                'required' => true,
                'options' => $channelOptions,
                'show_when' => [
                    'field' => 'relationship_status',
                    'equals' => self::DECISION_CONFIRMED,
                ],
            ],
            [
                'key' => 'attestation',
                'label' => 'I confirm these contacts already have the selected service/business communication relationship.',
                'type' => 'checkbox',
                'required' => true,
                'full_width' => true,
                'description' => 'This records existing service-message permission for the import. It does not create marketing permission.',
                'show_when' => [
                    'field' => 'relationship_status',
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
            ['relationship_status', 'channels', 'attestation'],
        ));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}" => 'Existing relationship permission received unsupported operator input.',
            ]);
        }

        $decision = $submitted['relationship_status'] ?? null;

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
                "post_import_inputs.{$this->key()}.relationship_status" => 'Choose a valid existing relationship option.',
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
                "post_import_inputs.{$this->key()}.channels" => 'Choose the channels supported by the existing relationship.',
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
                    "post_import_inputs.{$this->key()}.channels" => 'A selected service-message channel is not available for this import.',
                ]);
            }

            $selectedChannels[] = $channel;
        }

        $selectedChannels = array_values(array_unique($selectedChannels));

        if ($selectedChannels === []) {
            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}.channels" => 'Choose at least one channel supported by the existing relationship.',
            ]);
        }

        $attested = in_array(
            $submitted['attestation'] ?? null,
            [true, 1, '1', 'on', 'yes'],
            true,
        );

        if (! $attested) {
            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}.attestation" => 'Confirm that the selected service/business relationship already exists.',
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
                reasonCode: 'service_relationship_not_confirmed',
                message: 'Service-message permission was not imported because an existing relationship was not confirmed.',
            );
        }

        $channels = [];
        $applied = 0;
        $skipped = 0;
        $revoked = 0;
        $evidence = $config['operator_decision'] === self::DECISION_CONFIRMED
            ? 'operator_attested_existing_relationship'
            : 'server_configured_existing_relationship';

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
                purpose: MessagePurpose::Transactional,
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
                purpose: MessagePurpose::Transactional,
            );
            $latestConsent = $this->consentState->latestConsent(
                contact: $context->contact,
                channel: $channel,
                purpose: MessagePurpose::Transactional,
            );

            if ($latestRevocation !== null
                && ($latestConsent === null
                    || $latestRevocation->revoked_at->greaterThanOrEqualTo($latestConsent->consented_at))
            ) {
                $channels[$channel] = [
                    'state' => 'skipped',
                    'reason_code' => 'service_relationship_permission_revoked',
                    'revocation_id' => (int) $latestRevocation->getKey(),
                ];
                $skipped++;
                $revoked++;
                continue;
            }

            $result = $this->importMessageConsent->handle(
                contact: $context->contact,
                channel: $channel,
                purpose: MessagePurpose::Transactional->value,
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
            'purpose' => MessagePurpose::Transactional->value,
            'capture_scope' => $config['scope'],
            'permission_evidence' => $evidence,
            'channels' => $channels,
        ];

        if ($applied === 0) {
            return ContactImportPostProcessResult::skipped(
                reasonCode: $revoked > 0
                    ? 'service_relationship_permission_revoked'
                    : 'service_relationship_destination_unavailable',
                message: $revoked > 0
                    ? 'Service-message permission was not re-imported because at least one configured channel is currently revoked.'
                    : 'Service-message permission could not be imported because no configured channel had a usable destination.',
                meta: $meta,
            );
        }

        if ($skipped > 0) {
            return ContactImportPostProcessResult::partial(
                reasonCode: 'service_relationship_permission_partially_applied',
                message: 'Service-message permission was imported for available channels; at least one configured channel lacked a usable destination.',
                meta: $meta,
            );
        }

        return ContactImportPostProcessResult::applied(
            meta: $meta,
            message: 'Confirmed existing-relationship service-message permission was imported.',
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