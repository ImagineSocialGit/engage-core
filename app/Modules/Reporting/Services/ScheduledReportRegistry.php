<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Contracts\ScheduledReportProvider;
use Illuminate\Support\Collection;
use RuntimeException;

final class ScheduledReportRegistry
{
    /** @var Collection<string, ScheduledReportProvider> */
    private Collection $providers;

    public function __construct(iterable $providers)
    {
        $this->providers = collect($providers)
            ->mapWithKeys(function (mixed $provider): array {
                if (! $provider instanceof ScheduledReportProvider) {
                    throw new RuntimeException(
                        'Scheduled report contributors must implement '
                        .ScheduledReportProvider::class.'.',
                    );
                }

                $key = trim($provider->key());

                if ($key === '') {
                    throw new RuntimeException(
                        'Scheduled report provider keys cannot be empty.',
                    );
                }

                return [$key => $provider];
            });
    }

    /** @return Collection<string, ScheduledReportProvider> */
    public function all(): Collection
    {
        return $this->providers;
    }

    public function find(string $key): ?ScheduledReportProvider
    {
        $provider = $this->providers->get(trim($key));

        return $provider instanceof ScheduledReportProvider
            ? $provider
            : null;
    }

    public function require(string $key): ScheduledReportProvider
    {
        return $this->find($key)
            ?? throw new RuntimeException(
                "Scheduled report [{$key}] is not available.",
            );
    }
}