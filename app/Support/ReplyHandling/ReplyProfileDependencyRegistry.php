<?php

namespace App\Support\ReplyHandling;

use App\Support\ReplyHandling\Contracts\ReplyProfileDependencyContributor;
use App\Support\ReplyHandling\Data\ReplyProfileDependency;
use InvalidArgumentException;

final class ReplyProfileDependencyRegistry
{
    public const CONTRIBUTOR_TAG = 'reply_handling.dependency_contributors';

    /** @param iterable<int, ReplyProfileDependencyContributor> $contributors */
    public function __construct(
        private readonly iterable $contributors,
    ) {}

    /** @return array<int, ReplyProfileDependency> */
    public function all(): array
    {
        $dependencies = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof ReplyProfileDependencyContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Reply profile dependency contributor [%s] must implement [%s].',
                    get_debug_type($contributor),
                    ReplyProfileDependencyContributor::class,
                ));
            }

            foreach ($contributor->dependencies() as $dependency) {
                if (! $dependency instanceof ReplyProfileDependency) {
                    throw new InvalidArgumentException(sprintf(
                        'Reply profile dependency contributor [%s] returned an invalid dependency.',
                        $contributor::class,
                    ));
                }

                $dependencies[$dependency->key] = $dependency;
            }
        }

        uasort($dependencies, static fn (
            ReplyProfileDependency $left,
            ReplyProfileDependency $right,
        ): int => [
            $left->profileKey,
            ! $left->active,
            $left->moduleKey,
            $left->label,
            $left->key,
        ] <=> [
            $right->profileKey,
            ! $right->active,
            $right->moduleKey,
            $right->label,
            $right->key,
        ]);

        return array_values($dependencies);
    }

    /** @return array<int, ReplyProfileDependency> */
    public function forProfile(string $profileKey): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (ReplyProfileDependency $dependency): bool =>
                $dependency->profileKey === $profileKey,
        ));
    }

    /** @return array<int, ReplyProfileDependency> */
    public function forIntent(string $profileKey, string $intentKey): array
    {
        return array_values(array_filter(
            $this->forProfile($profileKey),
            fn (ReplyProfileDependency $dependency): bool =>
                $dependency->intentKey === $intentKey,
        ));
    }

    public function profileIsBlocked(string $profileKey): bool
    {
        return collect($this->forProfile($profileKey))
            ->contains(fn (ReplyProfileDependency $dependency): bool =>
                $dependency->blocksChanges);
    }

    public function intentIsBlocked(string $profileKey, string $intentKey): bool
    {
        return collect($this->forIntent($profileKey, $intentKey))
            ->contains(fn (ReplyProfileDependency $dependency): bool =>
                $dependency->blocksChanges);
    }
}