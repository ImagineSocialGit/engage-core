<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Support\Collection;

class FlowRouteMessageTemplateEligibilityResolver
{
    /** @var Collection<int, MessageTemplatePreset>|null */
    private ?Collection $resolved = null;

    /**
     * Return reusable message templates explicitly approved for direct Route authoring.
     *
     * Route authoring is opt-in. A preset or active catalog entry must set:
     *
     * meta.route_authoring.eligible = true
     *
     * This prevents Webinar, Campaign, permission-invitation, internal-notification,
     * and other lifecycle-owned templates from leaking into the generic Route editor.
     * Internal-purpose templates are never eligible for direct Route authoring.
     *
     * @return Collection<int, MessageTemplatePreset>
     */
    public function eligiblePresets(): Collection
    {
        if ($this->resolved instanceof Collection) {
            return $this->resolved;
        }

        if (! module_enabled('messaging')) {
            return $this->resolved = collect();
        }

        return $this->resolved = MessageTemplatePreset::query()
            ->active()
            ->with(['catalogEntries' => fn ($query) => $query->active()])
            ->orderBy('name')
            ->get()
            ->filter(fn (MessageTemplatePreset $preset): bool => $this->isEligible($preset))
            ->values();
    }

    public function isEligible(MessageTemplatePreset $preset): bool
    {
        if (! $preset->isActive() || $preset->dispatchKeys() === []) {
            return false;
        }

        if ($preset->purpose === 'internal') {
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
