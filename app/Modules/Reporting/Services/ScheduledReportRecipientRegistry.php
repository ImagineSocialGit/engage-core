<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Contracts\ScheduledReportRecipientOptionProvider;
use App\Modules\Reporting\Data\ScheduledReportRecipientOption;
use Illuminate\Support\Collection;
use RuntimeException;

final class ScheduledReportRecipientRegistry
{
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /** @return Collection<string, ScheduledReportRecipientOption> */
    public function options(): Collection
    {
        $options = collect();

        foreach ($this->providers as $provider) {
            if (! $provider instanceof ScheduledReportRecipientOptionProvider) {
                throw new RuntimeException(
                    'Scheduled report recipient contributors must implement '
                    .ScheduledReportRecipientOptionProvider::class.'.',
                );
            }

            foreach ($provider->options() as $option) {
                if (! $option instanceof ScheduledReportRecipientOption) {
                    throw new RuntimeException(
                        'Scheduled report recipient options must be '
                        .ScheduledReportRecipientOption::class.'.',
                    );
                }

                $options->put($option->key, $option);
            }
        }

        return $options->sortBy(
            fn (ScheduledReportRecipientOption $option): string =>
                mb_strtolower($option->label),
        );
    }
}