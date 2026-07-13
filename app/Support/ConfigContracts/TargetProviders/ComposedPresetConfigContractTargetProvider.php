<?php

namespace App\Support\ConfigContracts\TargetProviders;

use App\Support\ConfigContracts\Contracts\ConfigContractTargetProvider;
use App\Support\ConfigContracts\Data\ConfigContractTarget;
use App\Support\ConfigContracts\Data\ConfigContractTargetContext;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetPackageResolver;
use LogicException;
use Throwable;

abstract class ComposedPresetConfigContractTargetProvider implements ConfigContractTargetProvider
{
    public function __construct(
        private readonly PresetCompositionResolver $compositionResolver,
        private readonly PresetPackageResolver $packageResolver,
    ) {}

    final public function contractKeys(): array
    {
        return [$this->contractKey()];
    }

    final public function targets(ConfigContractTargetContext $context): iterable
    {
        if ($context->isProposed()) {
            throw new LogicException(
                'Composed preset config contract target discovery does not yet support proposed state.'
            );
        }

        $presetKey = $context->presetKey
            ?? $this->packageResolver->resolvePresetKey();

        if ($presetKey === null) {
            return;
        }

        try {
            $resolved = $this->compositionResolver->resolve(
                presetKey: $presetKey,
                domain: $this->presetDomain(),
            );
        } catch (Throwable) {
            // Preset composition validation owns malformed package/group resolution findings.
            return;
        }

        foreach ($resolved->definitions as $definitionKey => $definition) {
            $source = $resolved->provenance[$definitionKey]['source']
                ?? 'preset_composition.'.$this->presetDomain()->value;

            yield new ConfigContractTarget(
                contractKey: $this->contractKey(),
                path: "{$source}.definitions.{$definitionKey}",
                value: $definition,
                context: [
                    'preset_key' => $presetKey,
                    'preset_domain' => $this->presetDomain()->value,
                    'definition_key' => $definitionKey,
                    'group_keys' => $resolved->definitionGroups[$definitionKey] ?? [],
                    'contributor' => $resolved->provenance[$definitionKey]['contributor'] ?? null,
                ],
            );
        }
    }

    abstract protected function contractKey(): string;

    abstract protected function presetDomain(): PresetDomain;
}
