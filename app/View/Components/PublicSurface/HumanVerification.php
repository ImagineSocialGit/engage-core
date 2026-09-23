<?php

namespace App\View\Components\PublicSurface;

use App\Support\HumanVerification\HumanVerificationManager;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class HumanVerification extends Component
{
    /** @var array<string, mixed>|null */
    public readonly ?array $humanVerificationConfig;

    public function __construct(
        HumanVerificationManager $verification,
        string $surface,
        array $excludedPathPrefixes = [],
    ) {
        if (! $verification->enabledForSurface($surface)) {
            $this->humanVerificationConfig = null;

            return;
        }

        $widget = $verification->widgetForSurface($surface);

        $this->humanVerificationConfig = [
            'available' => $widget !== null,
            'responseField' => $verification->responseField(),
            'widget' => $widget?->toArray(),
            'excludedPathPrefixes' => $this->normalizeExcludedPathPrefixes(
                $excludedPathPrefixes,
            ),
        ];
    }

    public function shouldRender(): bool
    {
        return $this->humanVerificationConfig !== null;
    }

    public function render(): View
    {
        return view('components.public-surface.human-verification');
    }

    /**
     * @param array<int, mixed> $prefixes
     * @return array<int, string>
     */
    private function normalizeExcludedPathPrefixes(array $prefixes): array
    {
        $normalized = [];

        foreach ($prefixes as $prefix) {
            if (! is_string($prefix)) {
                continue;
            }

            $prefix = trim($prefix);

            if ($prefix === ''
                || ! str_starts_with($prefix, '/')
                || str_contains($prefix, '?')
                || str_contains($prefix, '#')
                || str_contains($prefix, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $prefix) === 1
            ) {
                continue;
            }

            $normalized[] = $prefix;
        }

        return array_values(array_unique($normalized));
    }
}