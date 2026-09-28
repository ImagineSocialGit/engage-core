<?php

namespace App\Modules\Campaigns\Import;

use App\Modules\Campaigns\Actions\RecordPriorCampaignMessageReceiptAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignPriorMessageReceipt;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessor;
use App\Modules\Core\Contracts\Contacts\ContactImportPostProcessorOperatorConfigProvider;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CampaignPriorMessageReceiptContactImportPostProcessor implements
    ContactImportPostProcessor,
    ContactImportPostProcessorOperatorConfigProvider
{
    public function __construct(
        private readonly RecordPriorCampaignMessageReceiptAction $record,
    ) {}

    public function key(): string
    {
        return 'campaign_prior_message_receipts';
    }

    public function label(): string
    {
        return 'Previously received Campaign messages';
    }

    public function sort(): int
    {
        return 180;
    }

    public function normalizeConfig(array $config): array
    {
        if (array_diff(array_keys($config), ['campaign_key', 'step_keys', 'campaign_options', 'campaign_locked']) !== []) {
            throw new InvalidArgumentException('Prior Campaign message import has unsupported configuration.');
        }

        $campaignKey = $config['campaign_key'] ?? null;
        $stepKeys = $config['step_keys'] ?? [];
        $options = $config['campaign_options'] ?? [];

        if ($campaignKey !== null && (! is_string($campaignKey) || trim($campaignKey) === '')) {
            throw new InvalidArgumentException('Prior Campaign message import requires a valid Campaign key.');
        }

        if (! is_array($stepKeys) || ! array_is_list($stepKeys)
            || ! is_array($options) || ! array_is_list($options)) {
            throw new InvalidArgumentException('Prior Campaign message selections must be lists.');
        }

        $stepKeys = array_values(array_unique($stepKeys));

        foreach ($stepKeys as $key) {
            if (! is_string($key) || trim($key) === '' || strlen($key) > 128) {
                throw new InvalidArgumentException('Prior Campaign message contains an invalid step key.');
            }
        }

        foreach ($options as $option) {
            if (! is_array($option)
                || ! is_string($option['value'] ?? null)
                || ! is_string($option['label'] ?? null)
                || ! is_array($option['steps'] ?? null)) {
                throw new InvalidArgumentException('Prior Campaign message options are invalid.');
            }
        }

        return [
            'campaign_key' => $campaignKey !== null ? trim($campaignKey) : null,
            'step_keys' => $stepKeys,
            'campaign_options' => $options,
            'campaign_locked' => (bool) ($config['campaign_locked'] ?? false),
        ];
    }

    public function operatorConfig(?array $configured): array
    {
        $configured = $this->normalizeConfig($configured ?? []);
        $options = $this->availableCampaigns();
        $lockedKey = $configured['campaign_key'];

        if ($lockedKey !== null) {
            $options = array_values(array_filter(
                $options,
                static fn (array $option): bool => $option['value'] === $lockedKey,
            ));
        }

        return [
            'campaign_key' => $lockedKey,
            'step_keys' => $configured['step_keys'],
            'campaign_options' => $options,
            'campaign_locked' => $lockedKey !== null,
        ];
    }

    public function inputDefinitions(array $config): array
    {
        $config = $this->normalizeConfig($config);

        if ($config['campaign_options'] === []) {
            return [];
        }

        $inputs = [[
            'key' => 'campaign_key',
            'label' => 'Campaign for previously received messages',
            'type' => 'select',
            'required' => false,
            'full_width' => true,
            'description' => 'Choose the Campaign whose messages these contacts received outside your system.',
            'options' => array_map(
                static fn (array $option): array => [
                    'value' => $option['value'],
                    'label' => $option['label'],
                ],
                $config['campaign_options'],
            ),
        ]];

        foreach ($config['campaign_options'] as $campaign) {
            foreach ($campaign['steps'] as $step) {
                $inputs[] = [
                    'key' => $this->inputKey($campaign['value'], $step['key']),
                    'label' => $step['label'],
                    'type' => 'select',
                    'required' => false,
                    'description' => 'Mark Yes only when every Contact in this import previously received this message.',
                    'options' => [
                        ['value' => '0', 'label' => 'No'],
                        ['value' => '1', 'label' => 'Yes, previously received'],
                    ],
                    'show_when' => [
                        'field' => 'campaign_key',
                        'equals' => $campaign['value'],
                    ],
                ];
            }
        }

        return $inputs;
    }

    public function withSubmittedInputs(array $config, array $submitted): array
    {
        $config = $this->normalizeConfig($config);
        $campaignKey = $submitted['campaign_key'] ?? $config['campaign_key'];

        if ($campaignKey === null || $campaignKey === '') {
            if (array_filter($submitted, static fn (mixed $value): bool => $value === '1')) {
                throw ValidationException::withMessages([
                    'post_import_inputs.'.$this->key().'.campaign_key' => 'Choose the Campaign for these prior messages.',
                ]);
            }

            return ['campaign_key' => null, 'step_keys' => []];
        }

        $campaign = collect($config['campaign_options'])->firstWhere('value', $campaignKey);

        if (! is_array($campaign)
            || ($config['campaign_locked'] && $campaignKey !== $config['campaign_key'])) {
            throw ValidationException::withMessages([
                'post_import_inputs.'.$this->key().'.campaign_key' => 'Choose an available Campaign.',
            ]);
        }

        $allowed = ['campaign_key'];
        $selected = [];

        foreach ($config['campaign_options'] as $option) {
            foreach ($option['steps'] as $step) {
                $inputKey = $this->inputKey($option['value'], $step['key']);
                $allowed[] = $inputKey;
                $choice = $submitted[$inputKey] ?? '0';

                if (! in_array($choice, ['0', '1', 0, 1], true)
                    || ($choice == '1' && $option['value'] !== $campaignKey)) {
                    throw ValidationException::withMessages([
                        'post_import_inputs.'.$this->key().'.'.$inputKey => 'Choose a message from the selected Campaign.',
                    ]);
                }

                if ($choice == '1') {
                    $selected[] = $step['key'];
                }
            }
        }

        if (array_diff(array_keys($submitted), $allowed) !== []) {
            throw ValidationException::withMessages([
                'post_import_inputs.'.$this->key() => 'Unsupported prior-message selection.',
            ]);
        }

        return [
            'campaign_key' => $campaignKey,
            'step_keys' => array_values(array_unique($selected)),
        ];
    }

    public function shouldProcess(array $config): bool
    {
        $config = $this->normalizeConfig($config);

        return $config['campaign_key'] !== null && $config['step_keys'] !== [];
    }

    public function summary(array $config): string
    {
        $config = $this->normalizeConfig($config);

        if ($config['step_keys'] === []) {
            return 'No prior Campaign messages will be recorded.';
        }

        return 'Record prior receipt of '.implode(', ', $config['step_keys'])
            .' for Campaign ['.$config['campaign_key'].'] on each imported Contact.';
    }

    public function handle(ContactImportContext $context, array $config): ContactImportPostProcessResult
    {
        $config = $this->normalizeConfig($config);
        $campaign = Campaign::query()->where('key', $config['campaign_key'])->first();

        if (! $campaign instanceof Campaign) {
            return ContactImportPostProcessResult::failed(
                reasonCode: 'campaign_unavailable',
                message: 'The selected Campaign no longer exists.',
            );
        }

        DB::transaction(function () use ($context, $config, $campaign): void {
            foreach ($config['step_keys'] as $key) {
                $this->record->handle(
                    contact: $context->contact,
                    campaign: $campaign,
                    messageStepKey: $key,
                    evidenceSource: CampaignPriorMessageReceipt::SOURCE_CONTACT_IMPORT,
                    source: $context->occurrence,
                );
            }
        });

        return ContactImportPostProcessResult::applied(meta: [
            'campaign_key' => $campaign->key,
            'message_step_keys' => $config['step_keys'],
            'recorded_count' => count($config['step_keys']),
        ]);
    }

    private function inputKey(string $campaignKey, string $stepKey): string
    {
        return 'received_'.substr(hash('sha256', $campaignKey."\0".$stepKey), 0, 16);
    }

    private function availableCampaigns(): array
    {
        return Campaign::query()
            ->whereNotNull('message_chain_id')
            ->whereHas('messageChain', fn ($query) => $query->whereNotNull('current_version_id'))
            ->with('messageChain.currentVersion.steps')
            ->orderBy('name')
            ->get()
            ->map(function (Campaign $campaign): ?array {
                $version = $campaign->messageChain?->currentVersion;
                $steps = $version?->steps
                    ->filter(fn ($step): bool => (bool) $step->is_active)
                    ->map(fn ($step): array => [
                        'key' => (string) $step->key,
                        'label' => (string) ($step->name ?: $step->key),
                    ])
                    ->values()
                    ->all() ?? [];

                return $steps !== [] ? [
                    'value' => (string) $campaign->key,
                    'label' => (string) $campaign->name,
                    'steps' => $steps,
                ] : null;
            })
            ->filter()
            ->values()
            ->all();
    }
}