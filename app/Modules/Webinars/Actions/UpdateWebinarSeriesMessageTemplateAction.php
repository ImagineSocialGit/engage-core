<?php

namespace App\Modules\Webinars\Actions;

use App\Models\User;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesMessageChainBinding;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UpdateWebinarSeriesMessageTemplateAction
{
    public function __construct(
        private readonly PublishMessageTemplateVersionAction $publishTemplateVersion,
        private readonly PublishMessageChainVersionAction $publishChainVersion,
        private readonly MessageTemplateTokenValidator $tokenValidator,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(
        WebinarSeries $series,
        MessageChainStepVariant $variant,
        array $payload,
        ?User $createdBy = null,
    ): MessageTemplateVersion {
        return DB::transaction(function () use (
            $series,
            $variant,
            $payload,
            $createdBy,
        ): MessageTemplateVersion {
            $targetSeries = WebinarSeries::query()
                ->whereKey($series->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $targetVariant = MessageChainStepVariant::query()
                ->with([
                    'messageChainStep.messageChainVersion.messageChain.currentVersion.steps.variants',
                    'messageTemplateVersion.messageTemplate',
                ])
                ->whereKey($variant->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $step = $targetVariant->messageChainStep;
            $version = $step?->messageChainVersion;
            $chain = $version?->messageChain;

            if (
                ! $step instanceof MessageChainStep
                || ! $chain instanceof MessageChain
            ) {
                throw new RuntimeException(
                    'The selected message variant is not attached to a MessageChain.',
                );
            }

            $bindings = WebinarSeriesMessageChainBinding::query()
                ->where('webinar_series_id', $targetSeries->getKey())
                ->where('message_chain_id', $chain->getKey())
                ->active()
                ->lockForUpdate()
                ->get();

            if ($bindings->isEmpty()) {
                throw ValidationException::withMessages([
                    'payload' => 'This message variant is not owned by the selected Webinar series.',
                ]);
            }

            if ((int) $chain->current_version_id !== (int) $version?->getKey()) {
                throw ValidationException::withMessages([
                    'payload' => 'This message chain changed after the page was loaded. Refresh and try again.',
                ]);
            }

            $binding = $this->bindingForVariant(
                bindings: $bindings,
                step: $step,
                variant: $targetVariant,
            );
            $currentTemplateVersion = $targetVariant->messageTemplateVersion;

            if (! $currentTemplateVersion instanceof MessageTemplateVersion) {
                throw new RuntimeException(
                    'The selected message variant has no immutable template version.',
                );
            }

            $mergedPayload = array_replace_recursive(
                $currentTemplateVersion->payload(),
                $payload,
            );

            $this->assertValidTokens(
                payload: $mergedPayload,
                binding: $binding,
                variant: $targetVariant,
            );

            $template = $this->seriesTemplate(
                series: $targetSeries,
                chain: $chain,
                step: $step,
                variant: $targetVariant,
                sourceVersion: $currentTemplateVersion,
            );
            $publishedTemplateVersion = $this->publishTemplateVersion->handle(
                messageTemplate: $template,
                payload: $mergedPayload,
                createdBy: $createdBy,
            );
            $definition = $chain->requireCurrentVersion()->definition();
            $steps = $this->replaceVariantTemplateVersion(
                steps: $definition['steps'],
                stepKey: $step->key,
                variantKey: $targetVariant->key,
                templateVersionId: (int) $publishedTemplateVersion->getKey(),
            );

            $this->publishChainVersion->handle(
                messageChain: $chain,
                steps: $steps,
                exitConditions: is_array($definition['exit_conditions'])
                    ? $definition['exit_conditions']
                    : [],
                createdBy: $createdBy,
            );

            $chain->forceFill([
                'is_customized' => true,
                'customized_at' => now(),
            ])->save();

            return $publishedTemplateVersion;
        }, 3);
    }

    /**
     * @param Collection<int, WebinarSeriesMessageChainBinding> $bindings
     */
    private function bindingForVariant(
        Collection $bindings,
        MessageChainStep $step,
        MessageChainStepVariant $variant,
    ): WebinarSeriesMessageChainBinding {
        $binding = $bindings->first(function (
            WebinarSeriesMessageChainBinding $candidate,
        ) use ($step): bool {
            return str_starts_with(
                $step->key,
                $candidate->message_area_key.'_',
            );
        });

        if ($binding instanceof WebinarSeriesMessageChainBinding) {
            return $binding;
        }

        $binding = $bindings->first(function (
            WebinarSeriesMessageChainBinding $candidate,
        ) use ($variant): bool {
            $area = $this->messageAreaRegistry->get(
                $candidate->message_area_key,
            );

            return $area?->messageType === $variant->message_type;
        });

        if ($binding instanceof WebinarSeriesMessageChainBinding) {
            return $binding;
        }

        throw new RuntimeException(
            "No Webinar message-area binding owns variant [{$variant->getKey()}].",
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertValidTokens(
        array $payload,
        WebinarSeriesMessageChainBinding $binding,
        MessageChainStepVariant $variant,
    ): void {
        $issues = $this->tokenValidator->validatePayload(
            payload: $payload,
            dispatchKeys: [$binding->dispatch_key],
            channel: $variant->channel,
            purpose: $variant->purpose,
            scope: $variant->scope,
            surface: $binding->surface,
        );
        $errors = array_values(array_filter(
            $issues,
            fn (array $issue): bool => ($issue['level'] ?? null) === 'error',
        ));

        if ($errors === []) {
            return;
        }

        $messages = [];

        foreach ($errors as $issue) {
            $path = is_string($issue['path'] ?? null)
                ? $issue['path']
                : 'payload';
            $message = is_string($issue['message'] ?? null)
                ? $issue['message']
                : 'The message template contains an invalid token.';

            $messages[$path][] = $message;
        }

        throw ValidationException::withMessages($messages);
    }

    private function seriesTemplate(
        WebinarSeries $series,
        MessageChain $chain,
        MessageChainStep $step,
        MessageChainStepVariant $variant,
        MessageTemplateVersion $sourceVersion,
    ): MessageTemplate {
        $key = $this->templateKey(
            series: $series,
            chain: $chain,
            step: $step,
            variant: $variant,
        );
        $template = MessageTemplate::query()->firstOrNew(['key' => $key]);
        $sourceTemplate = $sourceVersion->messageTemplate;
        $name = implode(' — ', array_filter([
            $series->title,
            $step->name ?: Str::headline($step->key),
            strtoupper($variant->channel),
        ]));

        $template->forceFill([
            'name' => mb_substr($name, 0, 191),
            'description' => $sourceTemplate?->description,
            'channel' => $variant->channel,
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'webinar_series',
            'source_version' => null,
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        return $template;
    }

    private function templateKey(
        WebinarSeries $series,
        MessageChain $chain,
        MessageChainStep $step,
        MessageChainStepVariant $variant,
    ): string {
        $chainSegment = Str::afterLast($chain->key, '.');
        $base = implode('.', [
            'webinar',
            'series',
            $series->getKey(),
            $this->segment($chainSegment),
            $this->segment($step->key),
            $this->segment($variant->key),
        ]);

        if (mb_strlen($base) <= 191) {
            return $base;
        }

        return mb_substr($base, 0, 150).'.'.hash('sha256', $base);
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @return array<int, array<string, mixed>>
     */
    private function replaceVariantTemplateVersion(
        array $steps,
        string $stepKey,
        string $variantKey,
        int $templateVersionId,
    ): array {
        $replaced = false;

        foreach ($steps as $stepIndex => $step) {
            if (($step['key'] ?? null) !== $stepKey) {
                continue;
            }

            foreach (($step['variants'] ?? []) as $variantIndex => $variant) {
                if (($variant['key'] ?? null) !== $variantKey) {
                    continue;
                }

                $steps[$stepIndex]['variants'][$variantIndex]['message_template_version_id']
                    = $templateVersionId;
                $replaced = true;
            }
        }

        if (! $replaced) {
            throw new RuntimeException(
                "MessageChain variant [{$stepKey}.{$variantKey}] could not be replaced.",
            );
        }

        return $steps;
    }

    private function segment(string $value): string
    {
        $value = str_replace('-', '_', strtolower(trim($value)));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';

        return trim($value, '_') ?: 'message';
    }
}