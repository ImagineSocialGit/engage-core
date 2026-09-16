<?php

namespace App\Support\ModuleIntegrations\Scheduling\Webinars;

use App\Modules\Scheduling\Contracts\BookingEligibilityProvider;
use App\Modules\Scheduling\Data\BookingEligibilityIdentity;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class WebinarRegistrantBookingEligibilityProvider implements BookingEligibilityProvider
{
    public function key(): string
    {
        return 'webinar_registrant';
    }

    public function label(): string
    {
        return 'Webinar registrant';
    }

    public function options(): array
    {
        return WebinarSeries::query()
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (WebinarSeries $series): array => [
                'value' => 'series:'.$series->getKey().':latest_started',
                'label' => $series->title.' · most recent started webinar',
                'group' => 'Webinar registration',
                'criteria' => [
                    'series_id' => (int) $series->getKey(),
                    'occurrence' => 'latest_started',
                ],
            ])
            ->values()
            ->all();
    }

    public function resolve(
        array $criteria,
        string $email,
        ?CarbonInterface $evaluatedAt = null,
    ): ?BookingEligibilityIdentity {
        $seriesId = $this->positiveInteger($criteria['series_id'] ?? null, 'series_id');
        $occurrence = is_string($criteria['occurrence'] ?? null)
            ? trim((string) $criteria['occurrence'])
            : '';

        if ($occurrence !== 'latest_started') {
            throw new InvalidArgumentException(
                "Unsupported webinar booking eligibility occurrence [{$occurrence}].",
            );
        }

        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        $at = $evaluatedAt !== null
            ? CarbonImmutable::instance($evaluatedAt)->utc()
            : CarbonImmutable::now('UTC');

        $webinar = Webinar::query()
            ->visible()
            ->matchingCurrentSeriesProvider()
            ->where('webinar_series_id', $seriesId)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', $at)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first(['id', 'webinar_series_id', 'starts_at']);

        if (! $webinar instanceof Webinar) {
            return null;
        }

        $registration = WebinarRegistration::query()
            ->with('contact:id,email')
            ->where('webinar_id', $webinar->getKey())
            ->where('status', '!=', 'cancelled')
            ->whereNull('cancelled_at')
            ->whereHas('contact', function (Builder $query) use ($email): void {
                $query->whereRaw('LOWER(email) = ?', [$email]);
            })
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->first();

        if (! $registration instanceof WebinarRegistration
            || ! $registration->contact
        ) {
            return null;
        }

        return new BookingEligibilityIdentity(
            contactId: (int) $registration->contact_id,
            scopeKey: 'webinar:'.$webinar->getKey(),
            meta: [
                'webinar_registration_id' => $registration->getKey(),
                'webinar_id' => $webinar->getKey(),
                'webinar_series_id' => $seriesId,
                'webinar_started_at' => $webinar->starts_at?->utc()->toISOString(),
                'registration_status' => $registration->status,
            ],
        );
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException(
                "Webinar booking eligibility criteria [{$field}] must be a positive integer.",
            );
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if (! is_int($integer) || $integer < 1) {
            throw new InvalidArgumentException(
                "Webinar booking eligibility criteria [{$field}] must be a positive integer.",
            );
        }

        return $integer;
    }
}