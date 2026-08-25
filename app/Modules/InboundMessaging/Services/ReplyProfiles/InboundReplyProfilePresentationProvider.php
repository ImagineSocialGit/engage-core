<?php

namespace App\Modules\InboundMessaging\Services\ReplyProfiles;

use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\InboundMessaging\Models\InboundReplyRule;
use App\Support\ReplyHandling\Contracts\ReplyProfilePresentationProvider;
use App\Support\ReplyHandling\Data\ReplyProfilePresentation;
use App\Support\ReplyHandling\ReplyProfileDependencyRegistry;

final class InboundReplyProfilePresentationProvider implements ReplyProfilePresentationProvider
{
    public function __construct(
        private readonly ReplyProfileDependencyRegistry $dependencies,
    ) {}

    public function profiles(): iterable
    {
        $dependencies = collect($this->dependencies->all())
            ->groupBy(fn ($dependency): string => $dependency->profileKey);

        foreach (InboundReplyProfile::query()
            ->with('intents.rules')
            ->orderBy('label')
            ->orderBy('key')
            ->get() as $profile
        ) {
            yield new ReplyProfilePresentation(
                key: (string) $profile->key,
                label: (string) $profile->label,
                description: $profile->description
                    ? (string) $profile->description
                    : null,
                active: (bool) $profile->is_active,
                intents: $profile->intents
                    ->map(function ($intent): array {
                        $rules = $intent->rules
                            ->where('is_active', true)
                            ->groupBy('match_type');

                        $exact = $rules
                            ->get(InboundReplyRule::MATCH_EXACT, collect())
                            ->pluck('value')
                            ->map(fn (mixed $value): string => (string) $value)
                            ->values()
                            ->all();
                        $keywords = $rules
                            ->get(InboundReplyRule::MATCH_KEYWORD, collect())
                            ->pluck('value')
                            ->map(fn (mixed $value): string => (string) $value)
                            ->values()
                            ->all();

                        return [
                            'key' => (string) $intent->key,
                            'label' => (string) $intent->label,
                            'description' => $intent->description
                                ? (string) $intent->description
                                : null,
                            'active' => (bool) $intent->is_active,
                            'exact' => $exact,
                            'keywords' => $keywords,
                            'exact_text' => implode("\n", $exact),
                            'keywords_text' => implode("\n", $keywords),
                        ];
                    })
                    ->values()
                    ->all(),
                dependencies: $dependencies
                    ->get($profile->key, collect())
                    ->map(fn ($dependency): array => $dependency->toArray())
                    ->values()
                    ->all(),
                updateUrl: route(
                    'crm.inbound-messaging.reply-profiles.update',
                    $profile,
                ),
                detailsUrl: route(
                    'crm.inbound-messaging.reply-profiles.index',
                    ['profile' => $profile->key],
                ),
            );
        }
    }

    public function indexUrl(): ?string
    {
        return route('crm.inbound-messaging.reply-profiles.index');
    }
}