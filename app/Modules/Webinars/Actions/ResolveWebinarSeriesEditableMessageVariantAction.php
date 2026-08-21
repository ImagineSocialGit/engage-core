<?php

namespace App\Modules\Webinars\Actions;

use App\Models\User;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesMessageChainBinding;
use App\Modules\Webinars\Services\WebinarMessageChainBindingResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ResolveWebinarSeriesEditableMessageVariantAction
{
    public function __construct(
        private readonly WebinarMessageChainBindingResolver $bindingResolver,
        private readonly DuplicateWebinarSeriesMessageChainsAction $duplicateMessageChains,
    ) {}

    public function handle(
        WebinarSeries $series,
        MessageChainStepVariant $variant,
        ?User $createdBy = null,
    ): MessageChainStepVariant {
        $variant->loadMissing(
            'messageChainStep.messageChainVersion.messageChain',
        );

        $step = $variant->messageChainStep;
        $version = $step?->messageChainVersion;
        $sourceChain = $version?->messageChain;

        if (! $step instanceof MessageChainStep || ! $sourceChain instanceof MessageChain) {
            throw new RuntimeException(
                'The selected Webinar message is not attached to a MessageChain.',
            );
        }

        if ($this->seriesOwnsChain($series, $sourceChain)) {
            return $variant;
        }

        $effectiveBindings = $this->bindingResolver->effectiveBindingsForSeries($series);
        $sourceBindings = $effectiveBindings
            ->filter(fn ($binding): bool =>
                (int) $binding->message_chain_id === (int) $sourceChain->getKey()
            )
            ->values();

        if ($sourceBindings->isEmpty()) {
            throw ValidationException::withMessages([
                'payload' => 'This message is no longer part of the selected Webinar series. Refresh and try again.',
            ]);
        }

        if (! WebinarSeriesMessageChainBinding::query()
            ->where('webinar_series_id', $series->getKey())
            ->active()
            ->exists()
        ) {
            $this->duplicateMessageChains->handle(
                targetSeries: $series,
                createdBy: $createdBy,
            );
        }

        return $this->resolveCopiedVariant(
            series: $series,
            sourceBindings: $sourceBindings,
            sourceStepKey: (string) $step->key,
            sourceVariantKey: (string) $variant->key,
        );
    }

    private function seriesOwnsChain(
        WebinarSeries $series,
        MessageChain $chain,
    ): bool {
        return WebinarSeriesMessageChainBinding::query()
            ->where('webinar_series_id', $series->getKey())
            ->where('message_chain_id', $chain->getKey())
            ->active()
            ->exists();
    }

    /**
     * @param Collection<int, mixed> $sourceBindings
     */
    private function resolveCopiedVariant(
        WebinarSeries $series,
        Collection $sourceBindings,
        string $sourceStepKey,
        string $sourceVariantKey,
    ): MessageChainStepVariant {
        foreach ($sourceBindings as $sourceBinding) {
            $targetBinding = WebinarSeriesMessageChainBinding::query()
                ->with('messageChain.currentVersion.steps.variants')
                ->where('webinar_series_id', $series->getKey())
                ->where('key', $sourceBinding->key)
                ->where('message_area_key', $sourceBinding->message_area_key)
                ->active()
                ->first();

            $targetVersion = $targetBinding?->messageChain?->currentVersion;

            if ($targetVersion === null) {
                continue;
            }

            $targetStep = $targetVersion->steps->first(
                fn (MessageChainStep $step): bool =>
                    (string) $step->key === $sourceStepKey,
            );

            if (! $targetStep instanceof MessageChainStep) {
                continue;
            }

            $targetVariant = $targetStep->variants->first(
                fn (MessageChainStepVariant $variant): bool =>
                    (string) $variant->key === $sourceVariantKey,
            );

            if ($targetVariant instanceof MessageChainStepVariant) {
                return $targetVariant;
            }
        }

        throw ValidationException::withMessages([
            'payload' => 'The Webinar message sequence changed while preparing this edit. Refresh and try again.',
        ]);
    }
}