<?php

namespace App\Modules\Webinars\Validation;

use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileChainBinding;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesMessageChainBinding;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use App\Modules\Webinars\Services\WebinarScheduleProfileResolver;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class WebinarMessageChainSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'webinar_message_chains';
    private const MODULE = 'webinars';

    public function __construct(
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
        private readonly WebinarScheduleProfileResolver $scheduleProfileResolver,
    ) {}

    public function findings(): iterable
    {
        if (! $this->tablesExist()) {
            return;
        }

        yield from $this->profileFindings();
        yield from $this->seriesFindings();
    }

    private function profileFindings(): iterable
    {
        $profiles = WebinarScheduleProfile::query()
            ->active()
            ->with([
                'items',
                'messageChainBindings.messageChain.currentVersion',
            ])
            ->get();

        foreach ($profiles as $profile) {
            $expectedAreas = $this->expectedAreasForProfile($profile);
            $activeBindings = $profile->messageChainBindings
                ->filter(fn (WebinarScheduleProfileChainBinding $binding): bool =>
                    $binding->is_active
                )
                ->keyBy('message_area_key');
            $path = "webinars.schedule_profiles.{$profile->key}";

            foreach ($expectedAreas as $areaKey => $area) {
                $binding = $activeBindings->get($areaKey);

                if (! $binding instanceof WebinarScheduleProfileChainBinding) {
                    yield $this->error(
                        code: 'webinars.message_chain.binding_missing',
                        message: "Webinar schedule profile [{$profile->key}] has no active MessageChain binding for area [{$areaKey}].",
                        path: $path,
                        context: [
                            'profile_key' => $profile->key,
                            'message_area_key' => $areaKey,
                        ],
                    );

                    continue;
                }

                yield from $this->bindingFindings(
                    binding: $binding,
                    expectedChainKey: $area->chainKey,
                    ownerLabel: "Webinar schedule profile [{$profile->key}]",
                    path: $path,
                    context: [
                        'profile_key' => $profile->key,
                        'message_area_key' => $areaKey,
                    ],
                );
            }
        }
    }

    private function seriesFindings(): iterable
    {
        $seriesCollection = WebinarSeries::query()
            ->with([
                'webinarScheduleProfile.messageChainBindings.messageChain.currentVersion',
                'messageChainBindings.messageChain.currentVersion',
            ])
            ->orderBy('id')
            ->get();

        foreach ($seriesCollection as $series) {
            $activeBindings = $series->messageChainBindings
                ->filter(fn (WebinarSeriesMessageChainBinding $binding): bool =>
                    $binding->is_active
                )
                ->keyBy('message_area_key');

            if ($activeBindings->isEmpty()) {
                continue;
            }

            $profile = $this->scheduleProfileResolver->resolveForSeries($series);
            $expectedAreaKeys = $profile instanceof WebinarScheduleProfile
                ? $profile->messageChainBindings
                    ->filter(fn (WebinarScheduleProfileChainBinding $binding): bool =>
                        $binding->is_active
                    )
                    ->pluck('message_area_key')
                    ->unique()
                    ->values()
                : collect();
            $path = "webinars.series.{$series->slug}";

            foreach ($expectedAreaKeys as $areaKey) {
                if ($activeBindings->has($areaKey)) {
                    continue;
                }

                yield $this->error(
                    code: 'webinars.message_chain.series_binding_incomplete',
                    message: "Webinar series [{$series->title}] owns custom MessageChains but has no active binding for area [{$areaKey}].",
                    path: $path,
                    context: [
                        'webinar_series_id' => $series->getKey(),
                        'message_area_key' => $areaKey,
                    ],
                );
            }

            foreach ($activeBindings as $areaKey => $binding) {
                $area = $this->messageAreaRegistry->get((string) $areaKey);

                if (! $area?->enabled || ! $area->isTemplate()) {
                    yield $this->error(
                        code: 'webinars.message_chain.series_binding_area_invalid',
                        message: "Webinar series [{$series->title}] has a custom MessageChain binding for unavailable area [{$areaKey}].",
                        path: $path,
                        context: [
                            'webinar_series_id' => $series->getKey(),
                            'message_area_key' => $areaKey,
                        ],
                    );

                    continue;
                }

                yield from $this->bindingFindings(
                    binding: $binding,
                    expectedChainKey: $area->chainKey,
                    ownerLabel: "Webinar series [{$series->title}]",
                    path: $path,
                    context: [
                        'webinar_series_id' => $series->getKey(),
                        'message_area_key' => $areaKey,
                    ],
                );
            }
        }
    }

    /**
     * @return Collection<string, mixed>
     */
    private function expectedAreasForProfile(
        WebinarScheduleProfile $profile,
    ): Collection {
        return $profile->items
            ->filter(fn (WebinarScheduleProfileItem $item): bool =>
                $item->is_active && $item->is_enabled
            )
            ->map(fn (WebinarScheduleProfileItem $item) =>
                $this->messageAreaRegistry->areaForScheduleItem($item)
            )
            ->filter(fn (mixed $area): bool =>
                $area?->enabled === true && $area->isTemplate()
            )
            ->keyBy('key');
    }

    private function bindingFindings(
        WebinarScheduleProfileChainBinding|WebinarSeriesMessageChainBinding $binding,
        string $expectedChainKey,
        string $ownerLabel,
        string $path,
        array $context,
    ): iterable {
        $areaKey = $binding->message_area_key;

        if ($binding->key !== $expectedChainKey) {
            yield $this->error(
                code: 'webinars.message_chain.binding_key_mismatch',
                message: "{$ownerLabel} area [{$areaKey}] is bound through [{$binding->key}] instead of [{$expectedChainKey}].",
                path: $path,
                context: [
                    ...$context,
                    'binding_key' => $binding->key,
                    'expected_binding_key' => $expectedChainKey,
                ],
            );
        }

        $chain = $binding->messageChain;
        $version = $chain?->currentVersion;

        if (! $chain instanceof MessageChain) {
            yield $this->error(
                code: 'webinars.message_chain.chain_missing',
                message: "{$ownerLabel} area [{$areaKey}] references a missing MessageChain.",
                path: $path,
                context: $context,
            );

            return;
        }

        if (! $chain->isActive()) {
            yield $this->error(
                code: 'webinars.message_chain.chain_inactive',
                message: "{$ownerLabel} area [{$areaKey}] references inactive MessageChain [{$chain->key}].",
                path: $path,
                context: [
                    ...$context,
                    'message_chain_key' => $chain->key,
                ],
            );
        }

        if (
            ! $version instanceof MessageChainVersion
            || ! $version->isPublished()
        ) {
            yield $this->error(
                code: 'webinars.message_chain.current_version_missing',
                message: "Webinar MessageChain [{$chain->key}] has no published current version.",
                path: $path,
                context: [
                    ...$context,
                    'message_chain_key' => $chain->key,
                ],
            );
        }
    }

    private function tablesExist(): bool
    {
        return Schema::hasTable('webinar_schedule_profiles')
            && Schema::hasTable('webinar_schedule_profile_items')
            && Schema::hasTable('webinar_schedule_profile_chain_bindings')
            && Schema::hasTable('webinar_series')
            && Schema::hasTable('webinar_series_message_chain_bindings')
            && Schema::hasTable('message_chains')
            && Schema::hasTable('message_chain_versions');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function error(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }
}