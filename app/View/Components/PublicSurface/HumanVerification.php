<?php

namespace App\View\Components\PublicSurface;

use App\Support\HumanVerification\HumanVerificationManager;
use App\Support\HumanVerification\PublicHumanVerificationGrantStore;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class HumanVerification extends Component
{
    /**
     * @param array<int, string> $excludedPathPrefixes
     */
    public function __construct(
        public readonly string $surface,
        public readonly array $excludedPathPrefixes = [],
    ) {}

    public function shouldRender(): bool
    {
        $manager = app(HumanVerificationManager::class);

        if (! $manager->enabledForSurface($this->surface)) {
            return false;
        }

        return ! app(PublicHumanVerificationGrantStore::class)
            ->hasValidGrant(
                session: request()->session(),
                surface: $this->surface,
                hostname: request()->getHost(),
            );
    }

    public function render(): View
    {
        $manager = app(HumanVerificationManager::class);
        $widget = $manager->widgetForSurface($this->surface);

        return view('components.public-surface.human-verification', [
            'humanVerificationConfig' => [
                'available' => $widget !== null,
                'surface' => $this->surface,
                'responseField' => $manager->responseField(),
                'excludedPathPrefixes' => array_values(array_filter(
                    array_map(
                        static fn (mixed $path): string => is_string($path)
                            ? trim($path)
                            : '',
                        $this->excludedPathPrefixes,
                    ),
                    static fn (string $path): bool => $path !== '',
                )),
                'widget' => $widget?->toArray(),
            ],
        ]);
    }
}