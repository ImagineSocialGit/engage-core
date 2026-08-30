<?php

namespace App\Modules\Campaigns\Import;

use App\Modules\Campaigns\Actions\ApplyAutomaticCampaignEligibilityAction;
use App\Modules\Campaigns\Actions\ScheduleCampaignImportBatchInitialMessagesAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Services\CampaignEligibilityReconciliationPlanner;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessor;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessorBatchFinalizer;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessorOperatorConfigProvider;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CampaignLaunchTimingContactImportPostProcessor implements
    ContactImportPostProcessor,
    ContactImportPostProcessorOperatorConfigProvider,
    ContactImportPostProcessorBatchFinalizer
{
    private const KEY_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';
    private const LAUNCH_NONE = 'none';
    private const LAUNCH_NOW = 'now';
    private const LAUNCH_SCHEDULED = 'scheduled';
    private const LAUNCH_MODES = [
        self::LAUNCH_NONE,
        self::LAUNCH_NOW,
        self::LAUNCH_SCHEDULED,
    ];

    public function __construct(
        private readonly ScheduleCampaignImportBatchInitialMessagesAction $scheduleBatch,
        private readonly ApplyAutomaticCampaignEligibilityAction $applyAutomaticEligibility,
        private readonly CampaignEligibilityReconciliationPlanner $reconciliationPlanner,
    ) {}

    public function key(): string
    {
        return 'campaign_launch_timing';
    }

    public function label(): string
    {
        return 'Campaign launch timing';
    }

    public function sort(): int
    {
        return 200;
    }

    public function normalizeConfig(array $config): array
    {
        $unknown = array_values(array_diff(
            array_keys($config),
            [
                'campaign_key',
                'first_message_at',
                'launch_mode',
                'campaign_options',
                'campaign_locked',
            ],
        ));

        if ($unknown !== []) {
            sort($unknown);

            throw new InvalidArgumentException(sprintf(
                'Contact import Campaign launch timing contains unknown field(s): %s.',
                implode(', ', $unknown),
            ));
        }

        $campaignKey = $this->nullableString($config['campaign_key'] ?? null);
        $operatorConfig = array_key_exists('launch_mode', $config)
            || array_key_exists('campaign_options', $config)
            || array_key_exists('campaign_locked', $config);

        if (($campaignKey === null && ! $operatorConfig)
            || ($campaignKey !== null
                && ! preg_match(self::KEY_PATTERN, $campaignKey))
        ) {
            throw new InvalidArgumentException(
                'Contact import Campaign launch timing [campaign_key] must be a lowercase snake_case key.',
            );
        }

        $firstMessageAt = $config['first_message_at'] ?? null;

        if ($firstMessageAt !== null
            && (! is_string($firstMessageAt) || trim($firstMessageAt) === '')
        ) {
            throw new InvalidArgumentException(
                'Contact import Campaign launch timing [first_message_at] must be a non-empty ISO timestamp when supplied.',
            );
        }

        $launchMode = $this->nullableString($config['launch_mode'] ?? null);

        if ($launchMode !== null && ! in_array($launchMode, self::LAUNCH_MODES, true)) {
            throw new InvalidArgumentException(
                'Contact import Campaign launch timing [launch_mode] is invalid.',
            );
        }

        $campaignOptions = $this->normalizeCampaignOptions(
            $config['campaign_options'] ?? [],
        );

        return [
            'campaign_key' => $campaignKey,
            'first_message_at' => is_string($firstMessageAt)
                ? CarbonImmutable::parse(trim($firstMessageAt))->utc()->toISOString()
                : null,
            'launch_mode' => $launchMode,
            'campaign_options' => $campaignOptions,
            'campaign_locked' => (bool) ($config['campaign_locked'] ?? false),
        ];
    }

    public function operatorConfig(?array $configured): array
    {
        $configured = $configured !== null
            ? $this->normalizeConfig($configured)
            : null;
        $lockedCampaignKey = $configured['campaign_key'] ?? null;
        $options = $this->availableCampaignOptions();

        if ($lockedCampaignKey !== null
            && ! collect($options)->contains(
                fn (array $option): bool => $option['value'] === $lockedCampaignKey,
            )
        ) {
            $options[] = [
                'value' => $lockedCampaignKey,
                'label' => Str::headline($lockedCampaignKey).' — selected by import profile',
            ];
        }

        return [
            'campaign_key' => $lockedCampaignKey
                ?? ($options[0]['value'] ?? null),
            'first_message_at' => $configured['first_message_at'] ?? null,
            'launch_mode' => $configured !== null
                ? self::LAUNCH_SCHEDULED
                : self::LAUNCH_NONE,
            'campaign_options' => $lockedCampaignKey !== null
                ? array_values(array_filter(
                    $options,
                    fn (array $option): bool => $option['value'] === $lockedCampaignKey,
                ))
                : $options,
            'campaign_locked' => $lockedCampaignKey !== null,
        ];
    }

    public function shouldProcess(array $config): bool
    {
        $config = $this->normalizeConfig($config);

        return $config['launch_mode'] !== self::LAUNCH_NONE
            && $config['campaign_key'] !== null
            && $config['first_message_at'] !== null;
    }

    public function summary(array $config): string
    {
        $config = $this->normalizeConfig($config);

        if ($config['launch_mode'] === self::LAUNCH_NONE) {
            return $config['campaign_options'] === []
                ? 'No ready automatic Campaign is currently available to start from this import.'
                : 'Optionally start an applicable automatic Campaign after every Contact row has finished importing.';
        }

        if ($config['campaign_key'] === null) {
            return 'Choose an automatic Campaign to start after this import.';
        }

        return "Resolve Campaign [{$config['campaign_key']}] through its normal enrollment policy, then apply the selected first-message time after the whole import batch finishes.";
    }

    public function inputDefinitions(array $config): array
    {
        $config = $this->normalizeConfig($config);
        $timezone = $this->timezone();

        if ($config['campaign_options'] === []) {
            return [];
        }

        return [
            [
                'key' => 'launch_mode',
                'label' => 'Start an applicable Campaign after this import?',
                'type' => 'select',
                'required' => true,
                'full_width' => true,
                'description' => 'Only Contacts who satisfy the selected Campaign’s saved eligibility rules will enroll. Event-only Flow Routes still wait for their real event.',
                'options' => [
                    [
                        'value' => self::LAUNCH_NONE,
                        'label' => 'Import only — do not start a Campaign',
                    ],
                    [
                        'value' => self::LAUNCH_NOW,
                        'label' => 'Start as soon as the import completes',
                    ],
                    [
                        'value' => self::LAUNCH_SCHEDULED,
                        'label' => 'Schedule the first Campaign message',
                    ],
                ],
            ],
            [
                'key' => 'campaign_key',
                'label' => 'Campaign',
                'type' => 'select',
                'required' => true,
                'full_width' => true,
                'description' => $config['campaign_locked']
                    ? 'This Campaign was selected by the detected import profile.'
                    : 'Available Campaigns are active, automatic, have saved eligibility rules, and have a published message journey.',
                'options' => $config['campaign_options'],
            ],
            [
                'key' => 'first_message_at',
                'label' => 'Start sending',
                'type' => 'datetime-local',
                'required' => false,
                'full_width' => true,
                'description' => "Choose when the first Campaign email/SMS should become due. Time is shown in {$timezone}. The whole batch finishes before eligibility is resolved and launch timing is applied.",
                'show_when' => [
                    'field' => 'launch_mode',
                    'equals' => self::LAUNCH_SCHEDULED,
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
            ['launch_mode', 'campaign_key', 'first_message_at'],
        ));

        if ($unknown !== []) {
            sort($unknown);

            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}" => 'Campaign launch timing received unsupported operator input.',
            ]);
        }

        $launchMode = $this->nullableString($submitted['launch_mode'] ?? null);

        if ($launchMode === null
            && $config['campaign_key'] !== null
            && $this->nullableString($submitted['first_message_at'] ?? null) !== null
        ) {
            $launchMode = self::LAUNCH_SCHEDULED;
        }

        $launchMode ??= self::LAUNCH_NONE;

        if (! in_array($launchMode, self::LAUNCH_MODES, true)) {
            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}.launch_mode" => 'Choose whether and when to start a Campaign.',
            ]);
        }

        if ($launchMode === self::LAUNCH_NONE) {
            return [
                'campaign_key' => null,
                'first_message_at' => null,
                'launch_mode' => self::LAUNCH_NONE,
            ];
        }

        $campaignKey = $this->nullableString(
            $submitted['campaign_key'] ?? $config['campaign_key'],
        );
        $allowedCampaignKeys = array_column(
            $config['campaign_options'],
            'value',
        );

        if ($config['campaign_key'] !== null) {
            $allowedCampaignKeys[] = $config['campaign_key'];
        }

        $allowedCampaignKeys = array_values(array_unique($allowedCampaignKeys));

        if ($campaignKey === null || ! in_array($campaignKey, $allowedCampaignKeys, true)) {
            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}.campaign_key" => 'Choose an available Campaign.',
            ]);
        }

        if ($launchMode === self::LAUNCH_NOW) {
            return [
                'campaign_key' => $campaignKey,
                'first_message_at' => CarbonImmutable::now('UTC')->toISOString(),
                'launch_mode' => self::LAUNCH_NOW,
            ];
        }

        $value = $submitted['first_message_at'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}.first_message_at" => 'Choose when the first Campaign messages should start sending.',
            ]);
        }

        $value = trim($value);
        $timezone = $this->timezone();

        try {
            $local = CarbonImmutable::createFromFormat(
                'Y-m-d\TH:i',
                $value,
                $timezone,
            );
        } catch (\Throwable) {
            $local = null;
        }

        if (! $local instanceof CarbonImmutable
            || $local->format('Y-m-d\TH:i') !== $value
        ) {
            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}.first_message_at" => 'Choose a valid Campaign start date and time.',
            ]);
        }

        return [
            'campaign_key' => $campaignKey,
            'first_message_at' => $local->utc()->toISOString(),
            'launch_mode' => self::LAUNCH_SCHEDULED,
        ];
    }

    public function handle(
        ContactImportContext $context,
        array $config,
    ): ContactImportPostProcessResult {
        $config = $this->requiredRuntimeConfig($config);

        $this->prepareAutomaticEnrollment(
            context: $context,
            campaignKey: $config['campaign_key'],
        );

        $enrollment = CampaignEnrollment::query()
            ->with('messageChainEnrollment')
            ->where('contact_id', $context->contact->getKey())
            ->where('campaign_key', $config['campaign_key'])
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
            return ContactImportPostProcessResult::blocked(
                reasonCode: 'campaign_launch_enrollment_missing',
                message: "Campaign [{$config['campaign_key']}] was not opened by the Contact's current Campaign enrollment policy.",
                meta: [
                    'campaign_key' => $config['campaign_key'],
                ],
            );
        }

        $chainEnrollment = $enrollment->messageChainEnrollment;

        if (! $chainEnrollment instanceof MessageChainEnrollment
            || $chainEnrollment->status !== MessageChainEnrollment::STATUS_ACTIVE
        ) {
            return ContactImportPostProcessResult::blocked(
                reasonCode: 'campaign_launch_enrollment_not_active',
                message: "Campaign [{$config['campaign_key']}] is not in a safe active state for initial launch timing.",
                meta: [
                    'campaign_key' => $config['campaign_key'],
                    'campaign_enrollment_id' => (int) $enrollment->getKey(),
                ],
            );
        }

        $batchStartedAt = $context->batch->imported_at;
        $enrollmentStartedAt = $enrollment->started_at;

        if ($batchStartedAt === null
            || $enrollmentStartedAt === null
            || $enrollmentStartedAt->lt($batchStartedAt)
        ) {
            return ContactImportPostProcessResult::skipped(
                reasonCode: 'campaign_launch_existing_enrollment_preserved',
                message: "An existing Campaign [{$config['campaign_key']}] enrollment predates this import and was left on its existing schedule.",
                meta: [
                    'campaign_key' => $config['campaign_key'],
                    'campaign_enrollment_id' => (int) $enrollment->getKey(),
                ],
            );
        }

        if ($chainEnrollment->current_message_chain_step_id === null
            || $chainEnrollment->next_action_at === null
            || ScheduledMessage::query()
                ->where('message_chain_enrollment_id', $chainEnrollment->getKey())
                ->exists()
        ) {
            return ContactImportPostProcessResult::blocked(
                reasonCode: 'campaign_launch_already_progressed',
                message: "Campaign [{$config['campaign_key']}] already progressed far enough that import launch timing cannot safely change its first message.",
                meta: [
                    'campaign_key' => $config['campaign_key'],
                    'campaign_enrollment_id' => (int) $enrollment->getKey(),
                    'message_chain_enrollment_id' => (int) $chainEnrollment->getKey(),
                ],
            );
        }

        return ContactImportPostProcessResult::applied(
            meta: [
                'campaign_key' => $config['campaign_key'],
                'campaign_enrollment_id' => (int) $enrollment->getKey(),
                'message_chain_enrollment_id' => (int) $chainEnrollment->getKey(),
            ],
            message: 'Campaign enrollment is staged for batch-level launch timing.',
        );
    }

    public function finalizeBatch(
        ContactImportBatch $batch,
        array $config,
    ): ContactImportPostProcessResult {
        $config = $this->requiredRuntimeConfig($config);

        $result = $this->scheduleBatch->handle(
            batch: $batch,
            campaignKey: $config['campaign_key'],
            firstMessageAt: $config['first_message_at'],
        );

        if ($result['enrollment_count'] < 1) {
            return ContactImportPostProcessResult::skipped(
                reasonCode: 'campaign_launch_no_new_enrollments',
                message: "No new Campaign [{$config['campaign_key']}] enrollments from this import were eligible for batch launch timing.",
                meta: $result,
            );
        }

        return ContactImportPostProcessResult::applied(
            meta: $result,
            message: sprintf(
                'Initial Campaign timing was applied to %d new enrollment(s).',
                $result['enrollment_count'],
            ),
        );
    }

    private function prepareAutomaticEnrollment(
        ContactImportContext $context,
        string $campaignKey,
    ): void {
        $campaign = Campaign::query()
            ->where('key', $campaignKey)
            ->first();

        if (! $campaign instanceof Campaign || ! $campaign->usesAutomaticEnrollment()) {
            return;
        }

        $campaigns = $this->reconciliationPlanner->targetWithOpenFamilyCampaigns(
            contact: $context->contact,
            target: $campaign,
        );

        foreach ($campaigns as $candidate) {
            $this->applyAutomaticEligibility->handle(
                campaign: $candidate,
                contact: $context->contact,
                eagerProcess: false,
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array{campaign_key: string, first_message_at: string}
     */
    private function requiredRuntimeConfig(array $config): array
    {
        $config = $this->normalizeConfig($config);
        $campaignKey = $config['campaign_key'];
        $firstMessageAt = $config['first_message_at'] ?? null;

        if ($campaignKey === null) {
            throw new InvalidArgumentException(
                'Campaign launch timing requires [campaign_key] before import processing.',
            );
        }

        if (! is_string($firstMessageAt) || trim($firstMessageAt) === '') {
            throw new InvalidArgumentException(
                'Campaign launch timing requires [first_message_at] before import processing.',
            );
        }

        return [
            'campaign_key' => $campaignKey,
            'first_message_at' => $firstMessageAt,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function availableCampaignOptions(): array
    {
        return Campaign::query()
            ->active()
            ->where('enrollment_mode', Campaign::ENROLLMENT_MODE_AUTOMATIC)
            ->whereNotNull('message_chain_id')
            ->whereHas('messageChain', fn ($query) => $query
                ->active()
                ->whereNotNull('current_version_id'))
            ->orderBy('name')
            ->orderBy('key')
            ->get(['key', 'name', 'eligibility_filter'])
            ->filter(fn (Campaign $campaign): bool => $campaign->hasEligibilityCriteria())
            ->map(fn (Campaign $campaign): array => [
                'value' => (string) $campaign->key,
                'label' => $this->campaignOptionLabel($campaign),
            ])
            ->values()
            ->all();
    }

    private function campaignOptionLabel(Campaign $campaign): string
    {
        $criteria = collect($campaign->eligibility_filter ?? [])
            ->filter(fn (mixed $values): bool => is_array($values) && $values !== [])
            ->map(function (array $values, mixed $key): string {
                $labels = collect($values)
                    ->filter(fn (mixed $value): bool => is_string($value) || is_numeric($value))
                    ->map(fn (mixed $value): string => Str::headline((string) $value))
                    ->implode(', ');

                return Str::headline((string) $key).': '.$labels;
            })
            ->filter()
            ->implode('; ');

        return trim((string) $campaign->name)
            .($criteria !== '' ? ' — eligible when '.$criteria : '');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function normalizeCampaignOptions(mixed $options): array
    {
        if (! is_array($options)) {
            throw new InvalidArgumentException(
                'Contact import Campaign launch timing [campaign_options] must be an array.',
            );
        }

        $normalized = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                throw new InvalidArgumentException(
                    'Contact import Campaign launch timing contains an invalid Campaign option.',
                );
            }

            $value = $this->nullableString($option['value'] ?? null);
            $label = $this->nullableString($option['label'] ?? null);

            if ($value === null
                || ! preg_match(self::KEY_PATTERN, $value)
                || $label === null
            ) {
                throw new InvalidArgumentException(
                    'Contact import Campaign launch timing contains an invalid Campaign option.',
                );
            }

            $normalized[$value] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return array_values($normalized);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function timezone(): string
    {
        $timezone = config('client.timezone', config('app.timezone', 'UTC'));

        return is_string($timezone)
            && in_array($timezone, timezone_identifiers_list(), true)
                ? $timezone
                : 'UTC';
    }
}