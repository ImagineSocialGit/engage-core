<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Contracts\BookingEligibilityProvider;
use App\Modules\Scheduling\Data\BookingEligibilityIdentity;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

final class BookingEligibilityProviderRegistry
{
    public const TAG = 'scheduling.booking_eligibility_providers';

    public function __construct(
        private readonly Container $container,
    ) {}

    /** @return array<string, BookingEligibilityProvider> */
    public function all(): array
    {
        $providers = [];

        foreach ($this->container->tagged(self::TAG) as $provider) {
            if (! $provider instanceof BookingEligibilityProvider) {
                throw new LogicException(
                    'Scheduling booking eligibility providers must implement BookingEligibilityProvider.',
                );
            }

            $key = trim($provider->key());

            if ($key === '') {
                throw new LogicException(
                    'Scheduling booking eligibility providers require a non-empty key.',
                );
            }

            if (isset($providers[$key])) {
                throw new LogicException(
                    "Duplicate Scheduling booking eligibility provider [{$key}].",
                );
            }

            $providers[$key] = $provider;
        }

        ksort($providers);

        return $providers;
    }

    public function provider(string $key): BookingEligibilityProvider
    {
        $key = trim($key);
        $provider = $this->all()[$key] ?? null;

        if (! $provider instanceof BookingEligibilityProvider) {
            throw new InvalidArgumentException(
                "Scheduling booking eligibility provider [{$key}] is unavailable.",
            );
        }

        return $provider;
    }

    /**
     * @return array<int, array{
     *     key:string,
     *     label:string,
     *     options:array<int, array{
     *         value:string,
     *         label:string,
     *         group:string|null,
     *         criteria:array<string, mixed>
     *     }>
     * }>
     */
    public function authoringDefinitions(): array
    {
        return array_values(array_map(
            static fn (BookingEligibilityProvider $provider): array => [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'options' => $provider->options(),
            ],
            $this->all(),
        ));
    }

    /** @param array<string, mixed> $criteria */
    public function resolve(
        string $provider,
        array $criteria,
        string $email,
        ?CarbonInterface $evaluatedAt = null,
    ): ?BookingEligibilityIdentity {
        return $this->provider($provider)->resolve(
            criteria: $criteria,
            email: $email,
            evaluatedAt: $evaluatedAt,
        );
    }

    public function label(string $provider): string
    {
        return $this->provider($provider)->label();
    }

    /** @return array<string, mixed> */
    public function criteriaForOption(string $provider, string $value): array
    {
        $option = collect($this->provider($provider)->options())
            ->first(fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['value'] ?? null) === $value);

        $criteria = is_array($option) ? ($option['criteria'] ?? null) : null;

        if (! is_array($criteria)) {
            throw new InvalidArgumentException(
                "Scheduling booking eligibility option [{$value}] is unavailable for provider [{$provider}].",
            );
        }

        return $criteria;
    }
}