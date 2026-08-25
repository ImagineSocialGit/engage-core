<?php

namespace App\Modules\InboundMessaging\Services\ReplyProfiles;

use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\InboundMessaging\Models\InboundReplyRule;
use App\Support\ReplyHandling\Data\ReplyProfileDependency;
use App\Support\ReplyHandling\ReplyProfileDependencyRegistry;
use Illuminate\Support\Collection;

final class ReplyProfileWorkspace
{
    public function __construct(
        private readonly ReplyProfileDependencyRegistry $dependencies,
    ) {}

    /** @return array<string, mixed> */
    public function build(?string $selectedKey = null): array
    {
        $profiles = InboundReplyProfile::query()
            ->with('intents.rules')
            ->orderBy('label')
            ->orderBy('key')
            ->get();
        $dependencies = collect($this->dependencies->all());
        $selected = $profiles->firstWhere('key', $selectedKey)
            ?? $profiles->first();

        return [
            'profiles' => $profiles,
            'selected_profile' => $selected,
            'selected_definition' => $selected instanceof InboundReplyProfile
                ? $this->definition($selected)
                : null,
            'selected_dependencies' => $selected instanceof InboundReplyProfile
                ? $dependencies
                    ->filter(fn (ReplyProfileDependency $dependency): bool =>
                        $dependency->profileKey === $selected->key)
                    ->values()
                : collect(),
            'dependency_counts' => $dependencies
                ->groupBy(fn (ReplyProfileDependency $dependency): string => $dependency->profileKey)
                ->map(fn (Collection $items): int => $items->count()),
            'active_count' => $profiles->where('is_active', true)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function definition(InboundReplyProfile $profile): array
    {
        return [
            'key' => $profile->key,
            'label' => $profile->label,
            'description' => $profile->description,
            'intents' => $profile->intents
                ->map(function ($intent): array {
                    $rules = $intent->rules->groupBy('match_type');

                    return [
                        'key' => $intent->key,
                        'label' => $intent->label,
                        'description' => $intent->description,
                        'is_active' => (bool) $intent->is_active,
                        'exact' => $rules
                            ->get(InboundReplyRule::MATCH_EXACT, collect())
                            ->pluck('value')
                            ->implode("\n"),
                        'keywords' => $rules
                            ->get(InboundReplyRule::MATCH_KEYWORD, collect())
                            ->pluck('value')
                            ->implode("\n"),
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}