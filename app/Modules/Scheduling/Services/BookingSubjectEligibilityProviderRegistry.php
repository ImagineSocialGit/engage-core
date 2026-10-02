<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Contracts\BookingSubjectEligibilityProvider;
use App\Modules\Scheduling\Data\BookingSubjectEligibilityContext;
use App\Modules\Scheduling\Models\BookableService;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

final class BookingSubjectEligibilityProviderRegistry
{
    public const TAG = 'scheduling.booking_subject_eligibility_providers';

    public function __construct(
        private readonly Container $container,
    ) {}

    /** @return array<string, BookingSubjectEligibilityProvider> */
    public function all(): array
    {
        $providers = [];

        foreach ($this->container->tagged(self::TAG) as $provider) {
            if (! $provider instanceof BookingSubjectEligibilityProvider) {
                throw new LogicException(
                    'Scheduling booking subject eligibility providers must implement BookingSubjectEligibilityProvider.',
                );
            }

            $key = trim($provider->key());

            if (preg_match('/\A[a-z0-9][a-z0-9_]{0,63}\z/', $key) !== 1) {
                throw new LogicException(
                    'Scheduling booking subject eligibility provider keys must be 1-64 lowercase letters, numbers, or underscores.',
                );
            }

            if (isset($providers[$key])) {
                throw new LogicException(
                    "Duplicate Scheduling booking subject eligibility provider [{$key}].",
                );
            }

            $providers[$key] = $provider;
        }

        ksort($providers);

        return $providers;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[trim($key)]);
    }

    public function provider(string $key): BookingSubjectEligibilityProvider
    {
        $key = trim($key);
        $provider = $this->all()[$key] ?? null;

        if (! $provider instanceof BookingSubjectEligibilityProvider) {
            throw new InvalidArgumentException(
                "Scheduling booking subject eligibility provider [{$key}] is unavailable.",
            );
        }

        return $provider;
    }

    public function validatePolicy(BookableService $service): void
    {
        $policy = $service->bookingSubjectPolicy();

        if ($policy === []) {
            return;
        }

        $this->provider($service->bookingSubjectKey())
            ->validatePolicy($policy);
    }

    public function assertEligible(BookingSubjectEligibilityContext $context): void
    {
        if ($context->policy === []) {
            return;
        }

        $this->provider($context->service->bookingSubjectKey())
            ->assertEligible($context);
    }
}