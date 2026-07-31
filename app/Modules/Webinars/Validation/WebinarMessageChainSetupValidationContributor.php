<?php

namespace App\Modules\Webinars\Validation;

use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileChainBinding;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Facades\Schema;

class WebinarMessageChainSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'webinar_schedule_profile_message_chains';
    private const MODULE = 'webinars';

    public function __construct(
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
    ) {}

    public function findings(): iterable
    {
        if (! $this->tablesExist()) {
            return;
        }

        $profiles = WebinarScheduleProfile::query()
            ->active()
            ->with([
                'items',
                'messageChainBindings.messageChain.currentVersion',
            ])
            ->get();

        foreach ($profiles as $profile) {
            $expectedAreas = $profile->items
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
            $activeBindings = $profile->messageChainBindings
                ->filter(fn (WebinarScheduleProfileChainBinding $binding): bool =>
                    $binding->is_active
                )
                ->keyBy('message_area_key');

            foreach ($expectedAreas as $areaKey => $area) {
                $binding = $activeBindings->get($areaKey);
                $path = "webinars.schedule_profiles.{$profile->key}";

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

                if ($binding->key !== $area->chainKey) {
                    yield $this->error(
                        code: 'webinars.message_chain.binding_key_mismatch',
                        message: "Webinar schedule profile [{$profile->key}] area [{$areaKey}] is bound through [{$binding->key}] instead of [{$area->chainKey}].",
                        path: $path,
                        context: [
                            'profile_key' => $profile->key,
                            'message_area_key' => $areaKey,
                            'binding_key' => $binding->key,
                            'expected_binding_key' => $area->chainKey,
                        ],
                    );
                }

                $chain = $binding->messageChain;
                $version = $chain?->currentVersion;

                if (! $chain instanceof MessageChain) {
                    yield $this->error(
                        code: 'webinars.message_chain.chain_missing',
                        message: "Webinar schedule profile [{$profile->key}] area [{$areaKey}] references a missing MessageChain.",
                        path: $path,
                        context: [
                            'profile_key' => $profile->key,
                            'message_area_key' => $areaKey,
                        ],
                    );

                    continue;
                }

                if (! $chain->isActive()) {
                    yield $this->error(
                        code: 'webinars.message_chain.chain_inactive',
                        message: "Webinar schedule profile [{$profile->key}] area [{$areaKey}] references inactive MessageChain [{$chain->key}].",
                        path: $path,
                        context: [
                            'profile_key' => $profile->key,
                            'message_area_key' => $areaKey,
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
                            'profile_key' => $profile->key,
                            'message_area_key' => $areaKey,
                            'message_chain_key' => $chain->key,
                        ],
                    );
                }
            }
        }
    }

    private function tablesExist(): bool
    {
        return Schema::hasTable('webinar_schedule_profiles')
            && Schema::hasTable('webinar_schedule_profile_items')
            && Schema::hasTable('webinar_schedule_profile_chain_bindings')
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