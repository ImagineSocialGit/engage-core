<?php

namespace App\Modules\Campaigns\Import;

use App\Modules\Campaigns\Actions\ScheduleCampaignImportBatchInitialMessagesAction;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessor;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessorBatchFinalizer;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessorInputProvider;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CampaignLaunchTimingContactImportPostProcessor implements
    ContactImportPostProcessor,
    ContactImportPostProcessorInputProvider,
    ContactImportPostProcessorBatchFinalizer
{
    private const KEY_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    public function __construct(
        private readonly ScheduleCampaignImportBatchInitialMessagesAction $scheduleBatch,
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
            ['campaign_key', 'first_message_at'],
        ));

        if ($unknown !== []) {
            sort($unknown);

            throw new InvalidArgumentException(sprintf(
                'Contact import Campaign launch timing contains unknown field(s): %s.',
                implode(', ', $unknown),
            ));
        }

        $campaignKey = $config['campaign_key'] ?? null;

        if (! is_string($campaignKey)
            || ! preg_match(self::KEY_PATTERN, trim($campaignKey))
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

        return [
            'campaign_key' => trim($campaignKey),
            'first_message_at' => is_string($firstMessageAt)
                ? CarbonImmutable::parse(trim($firstMessageAt))->utc()->toISOString()
                : null,
        ];
    }

    public function summary(array $config): string
    {
        $config = $this->normalizeConfig($config);

        return "Keep Campaign [{$config['campaign_key']}] on normal lifecycle routing, then apply the selected first-message time after the whole import batch finishes.";
    }

    public function inputDefinitions(array $config): array
    {
        $config = $this->normalizeConfig($config);
        $timezone = $this->timezone();

        return [[
            'key' => 'first_message_at',
            'label' => 'Start sending',
            'type' => 'datetime-local',
            'required' => true,
            'description' => "Choose when the first email/SMS touch for Campaign [{$config['campaign_key']}] should become due. Time is shown in {$timezone}. The batch is fully imported before this timing is applied.",
        ]];
    }

    public function withSubmittedInputs(
        array $config,
        array $submitted,
    ): array {
        $config = $this->normalizeConfig($config);
        $unknown = array_values(array_diff(
            array_keys($submitted),
            ['first_message_at'],
        ));

        if ($unknown !== []) {
            sort($unknown);

            throw ValidationException::withMessages([
                "post_import_inputs.{$this->key()}" => 'Campaign launch timing received unsupported operator input.',
            ]);
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
            ...$config,
            'first_message_at' => $local->utc()->toISOString(),
        ];
    }

    public function handle(
        ContactImportContext $context,
        array $config,
    ): ContactImportPostProcessResult {
        $config = $this->requiredRuntimeConfig($config);

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
                message: "Campaign [{$config['campaign_key']}] was not opened by the normal import treatment/lifecycle route.",
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

    /**
     * @param array<string, mixed> $config
     * @return array{campaign_key: string, first_message_at: string}
     */
    private function requiredRuntimeConfig(array $config): array
    {
        $config = $this->normalizeConfig($config);
        $firstMessageAt = $config['first_message_at'] ?? null;

        if (! is_string($firstMessageAt) || trim($firstMessageAt) === '') {
            throw new InvalidArgumentException(
                'Campaign launch timing requires [first_message_at] before import processing.',
            );
        }

        return [
            'campaign_key' => $config['campaign_key'],
            'first_message_at' => $firstMessageAt,
        ];
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