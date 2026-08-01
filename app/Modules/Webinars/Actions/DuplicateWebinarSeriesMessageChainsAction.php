<?php

namespace App\Modules\Webinars\Actions;

use App\Models\User;
use App\Modules\Messaging\Actions\DuplicateMessageChainAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Webinars\Models\WebinarScheduleProfileChainBinding;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesMessageChainBinding;
use App\Modules\Webinars\Services\WebinarMessageChainBindingResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class DuplicateWebinarSeriesMessageChainsAction
{
    public function __construct(
        private readonly DuplicateMessageChainAction $duplicateMessageChain,
        private readonly WebinarMessageChainBindingResolver $bindingResolver,
    ) {}

    /**
     * @return Collection<int, WebinarSeriesMessageChainBinding>
     */
    public function handle(
        WebinarSeries $targetSeries,
        ?WebinarSeries $sourceSeries = null,
        ?User $createdBy = null,
    ): Collection {
        return DB::transaction(function () use (
            $targetSeries,
            $sourceSeries,
            $createdBy,
        ): Collection {
            $target = WebinarSeries::query()
                ->whereKey($targetSeries->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $sourceSeries instanceof WebinarSeries
                && (int) $sourceSeries->getKey() === (int) $target->getKey()
            ) {
                throw new LogicException(
                    'A Webinar series cannot duplicate its message chains from itself.',
                );
            }

            if (WebinarSeriesMessageChainBinding::query()
                ->where('webinar_series_id', $target->getKey())
                ->active()
                ->exists()
            ) {
                throw new LogicException(
                    "Webinar series [{$target->title}] already owns custom message chains.",
                );
            }

            $source = $sourceSeries instanceof WebinarSeries
                ? WebinarSeries::query()->findOrFail($sourceSeries->getKey())
                : $target;
            $sourceBindings = $this->bindingResolver
                ->effectiveBindingsForSeries($source);

            if ($sourceBindings->isEmpty()) {
                throw new RuntimeException(
                    "Webinar series [{$source->title}] has no effective MessageChain bindings to duplicate.",
                );
            }

            $duplicatesBySourceChainId = [];

            foreach ($sourceBindings
                ->groupBy(fn ($binding): int => (int) $binding->message_chain_id)
                as $sourceChainId => $bindings
            ) {
                $firstBinding = $bindings->first();
                $sourceChain = $firstBinding?->messageChain;

                if (! $sourceChain instanceof MessageChain || ! $sourceChain->isActive()) {
                    throw new RuntimeException(
                        "Webinar series [{$source->title}] references an unavailable MessageChain.",
                    );
                }

                $bindingKey = $this->bindingKey($firstBinding);
                $duplicate = $this->duplicateMessageChain->handle(
                    source: $sourceChain,
                    key: $this->chainKey(
                        series: $target,
                        bindingKey: $bindingKey,
                    ),
                    name: $target->title.' — '.Str::headline($bindingKey).' Messages',
                    description: "Series-owned {$bindingKey} message chain for {$target->title}.",
                    createdBy: $createdBy,
                );

                $duplicatesBySourceChainId[(int) $sourceChainId] = $duplicate;
            }

            foreach ($sourceBindings as $sourceBinding) {
                $duplicate = $duplicatesBySourceChainId[
                    (int) $sourceBinding->message_chain_id
                ] ?? null;

                if (! $duplicate instanceof MessageChain) {
                    throw new RuntimeException(
                        'A duplicated Webinar MessageChain could not be resolved.',
                    );
                }

                WebinarSeriesMessageChainBinding::query()->create([
                    'webinar_series_id' => $target->getKey(),
                    'key' => $sourceBinding->key,
                    'message_area_key' => $sourceBinding->message_area_key,
                    'message_chain_id' => $duplicate->getKey(),
                    'dispatch_key' => $sourceBinding->dispatch_key,
                    'surface' => $sourceBinding->surface,
                    'is_active' => true,
                ]);
            }

            return WebinarSeriesMessageChainBinding::query()
                ->with('messageChain.currentVersion.steps.variants')
                ->where('webinar_series_id', $target->getKey())
                ->active()
                ->orderBy('key')
                ->orderBy('message_area_key')
                ->get();
        }, 3);
    }

    private function bindingKey(
        WebinarSeriesMessageChainBinding|WebinarScheduleProfileChainBinding|null $binding,
    ): string {
        $key = is_string($binding?->key)
            ? str_replace('-', '_', strtolower(trim($binding->key)))
            : '';

        if ($key === '') {
            throw new RuntimeException(
                'Webinar MessageChain binding key is required for duplication.',
            );
        }

        return $key;
    }

    private function chainKey(
        WebinarSeries $series,
        string $bindingKey,
    ): string {
        $base = implode('.', [
            'webinar',
            'series',
            $series->getKey(),
            $bindingKey,
        ]);

        if (mb_strlen($base) <= 191) {
            return $base;
        }

        return mb_substr($base, 0, 150).'.'.hash('sha256', $base);
    }
}