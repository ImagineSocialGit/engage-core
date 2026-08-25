<?php

namespace App\Support\ReplyHandling;

use App\Support\ReplyHandling\Contracts\ReplyProfilePresentationProvider;
use App\Support\ReplyHandling\Data\ReplyProfilePresentation;
use InvalidArgumentException;

final class ReplyProfilePresentationRegistry
{
    public const PROVIDER_TAG = 'reply_handling.presentation_providers';

    /** @var array<string, ReplyProfilePresentation>|null */
    private ?array $resolvedProfiles = null;

    /** @param iterable<int, ReplyProfilePresentationProvider> $providers */
    public function __construct(
        private readonly iterable $providers = [],
    ) {}

    /** @return array<string, ReplyProfilePresentation> */
    public function all(): array
    {
        if ($this->resolvedProfiles !== null) {
            return $this->resolvedProfiles;
        }

        $profiles = [];

        foreach ($this->providers as $provider) {
            if (! $provider instanceof ReplyProfilePresentationProvider) {
                throw new InvalidArgumentException(sprintf(
                    'Reply profile presentation provider [%s] must implement [%s].',
                    get_debug_type($provider),
                    ReplyProfilePresentationProvider::class,
                ));
            }

            foreach ($provider->profiles() as $profile) {
                if (! $profile instanceof ReplyProfilePresentation) {
                    throw new InvalidArgumentException(sprintf(
                        'Reply profile presentation provider [%s] returned an invalid profile.',
                        $provider::class,
                    ));
                }

                if (isset($profiles[$profile->key])) {
                    throw new InvalidArgumentException(
                        "Reply profile presentation [{$profile->key}] was contributed more than once.",
                    );
                }

                $profiles[$profile->key] = $profile;
            }
        }

        uasort(
            $profiles,
            static fn (ReplyProfilePresentation $left, ReplyProfilePresentation $right): int =>
                [! $left->active, $left->label, $left->key]
                <=> [! $right->active, $right->label, $right->key],
        );

        return $this->resolvedProfiles = $profiles;
    }

    public function find(?string $profileKey): ?ReplyProfilePresentation
    {
        if (! is_string($profileKey) || trim($profileKey) === '') {
            return null;
        }

        return $this->all()[trim($profileKey)] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function options(): array
    {
        return array_values(array_map(
            static fn (ReplyProfilePresentation $profile): array => [
                'value' => $profile->key,
                'label' => $profile->label,
                'active' => $profile->active,
            ],
            $this->all(),
        ));
    }

    public function indexUrl(): ?string
    {
        foreach ($this->providers as $provider) {
            if (! $provider instanceof ReplyProfilePresentationProvider) {
                continue;
            }

            $url = $provider->indexUrl();

            if (is_string($url) && trim($url) !== '') {
                return $url;
            }
        }

        return null;
    }
}