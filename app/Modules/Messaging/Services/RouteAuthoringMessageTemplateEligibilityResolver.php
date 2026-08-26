<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Support\Collection;

class RouteAuthoringMessageTemplateEligibilityResolver
{
    public const SELECTION_CONTEXT = 'flow_routes';

    /** @var Collection<int, MessageTemplatePreset>|null */
    private ?Collection $resolved = null;

    public function __construct(
        private readonly ReusableMessageTemplateCatalog $reusableTemplates,
    ) {}

    /** @return Collection<int, MessageTemplatePreset> */
    public function eligiblePresets(): Collection
    {
        if ($this->resolved instanceof Collection) {
            return $this->resolved;
        }

        if (! module_enabled('messaging')) {
            return $this->resolved = collect();
        }

        $contextual = $this->reusableTemplates->presets(
            selectionContext: self::SELECTION_CONTEXT,
        )->filter(fn (MessageTemplatePreset $preset): bool => $this->isContextualReusableEligible($preset));

        $legacy = MessageTemplatePreset::query()
            ->active()
            ->with(['catalogEntries' => fn ($query) => $query->active()])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (MessageTemplatePreset $preset): bool => $this->isLegacyEligible($preset));

        return $this->resolved = $contextual
            ->concat($legacy)
            ->unique(fn (MessageTemplatePreset $preset): int => (int) $preset->getKey())
            ->sortBy(fn (MessageTemplatePreset $preset): array => [
                (string) $preset->channel,
                (string) $preset->purpose,
                (string) $preset->name,
                (int) $preset->getKey(),
            ])
            ->values();
    }

    public function isEligible(MessageTemplatePreset $preset): bool
    {
        return $this->eligiblePresets()
            ->contains(fn (MessageTemplatePreset $candidate): bool => $candidate->is($preset));
    }

    private function isContextualReusableEligible(MessageTemplatePreset $preset): bool
    {
        return $preset->source === CreateReusableMessageTemplateAction::SOURCE
            && $preset->isActive()
            && $preset->purpose !== 'internal'
            && $preset->dispatchKeys() !== []
            && $preset->canonicalTemplate?->isActive() === true
            && $preset->canonicalTemplate?->currentVersion !== null;
    }

    private function isLegacyEligible(MessageTemplatePreset $preset): bool
    {
        if (! $preset->isActive() || $preset->dispatchKeys() === [] || $preset->purpose === 'internal') {
            return false;
        }

        if (data_get($preset->meta, 'route_authoring.eligible') === true) {
            return true;
        }

        return $preset->catalogEntries
            ->where('is_active', true)
            ->contains(
                fn ($entry): bool => data_get($entry->meta, 'route_authoring.eligible') === true,
            );
    }
}