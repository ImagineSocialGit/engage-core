<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Data\WebinarRegistrationReplacementChain;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Database\Eloquent\Builder;

class ResolveWebinarRegistrationReplacementChainAction
{
    private const MAX_REPLACEMENT_DEPTH = 50;

    public function handle(
        WebinarRegistration|int $registration,
        bool $lock = false,
    ): WebinarRegistrationReplacementChain {
        $registrationId = $registration instanceof WebinarRegistration
            ? (int) $registration->getKey()
            : $registration;

        $original = $this->registrationQuery($lock)
            ->findOrFail($registrationId);
        $current = $original;
        $originalSeriesId = $original->webinar?->webinar_series_id;
        $visited = [];
        $traversedRegistrationIds = [];
        $unresolvedReplacement = false;
        $cancelled = false;
        $cycleDetected = false;
        $seriesBoundaryViolated = false;
        $contactBoundaryViolated = false;
        $occurrenceBoundaryViolated = false;
        $terminated = false;

        for ($depth = 0; $depth < self::MAX_REPLACEMENT_DEPTH; $depth++) {
            $currentId = (int) $current->getKey();

            if (isset($visited[$currentId])) {
                $cycleDetected = true;
                $terminated = true;
                break;
            }

            $visited[$currentId] = true;
            $traversedRegistrationIds[] = $currentId;

            if (
                $current->status === 'cancelled'
                || $current->cancelled_at !== null
            ) {
                $cancelled = true;
                $terminated = true;
                break;
            }

            $replacement = $this->registrationQuery($lock)
                ->where('replacement_of_registration_id', $currentId)
                ->first();

            if ($replacement instanceof WebinarRegistration) {
                if ((int) $replacement->contact_id !== (int) $original->contact_id) {
                    $contactBoundaryViolated = true;
                    $terminated = true;
                    break;
                }

                if (! $this->sameSeries(
                    originalSeriesId: $originalSeriesId,
                    replacementSeriesId: $replacement->webinar?->webinar_series_id,
                )) {
                    $seriesBoundaryViolated = true;
                    $terminated = true;
                    break;
                }

                if (
                    ! $replacement->webinar
                    || (int) $replacement->webinar->replacement_of_webinar_id !== (int) $current->webinar_id
                ) {
                    $occurrenceBoundaryViolated = true;
                    $terminated = true;
                    break;
                }

                $current = $replacement;

                continue;
            }

            $replacementWebinar = $this->replacementWebinar(
                webinarId: $current->webinar_id,
                lock: $lock,
            );

            if ($replacementWebinar instanceof Webinar) {
                if (! $this->sameSeries(
                    originalSeriesId: $originalSeriesId,
                    replacementSeriesId: $replacementWebinar->webinar_series_id,
                )) {
                    $seriesBoundaryViolated = true;
                } else {
                    $unresolvedReplacement = true;
                }
            }

            $terminated = true;
            break;
        }

        if (! $terminated) {
            $cycleDetected = true;
        }

        return new WebinarRegistrationReplacementChain(
            original: $original,
            canonical: $current,
            traversedRegistrationIds: $traversedRegistrationIds,
            unresolvedReplacement: $unresolvedReplacement,
            cancelled: $cancelled,
            cycleDetected: $cycleDetected,
            seriesBoundaryViolated: $seriesBoundaryViolated,
            contactBoundaryViolated: $contactBoundaryViolated,
            occurrenceBoundaryViolated: $occurrenceBoundaryViolated,
        );
    }

    /** @return Builder<WebinarRegistration> */
    private function registrationQuery(bool $lock): Builder
    {
        $query = WebinarRegistration::query()->with([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]);

        return $lock ? $query->lockForUpdate() : $query;
    }

    private function replacementWebinar(
        int $webinarId,
        bool $lock,
    ): ?Webinar {
        $query = Webinar::query()
            ->where('replacement_of_webinar_id', $webinarId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function sameSeries(
        int|string|null $originalSeriesId,
        int|string|null $replacementSeriesId,
    ): bool {
        if ($originalSeriesId === null || $replacementSeriesId === null) {
            return $originalSeriesId === $replacementSeriesId;
        }

        return (int) $originalSeriesId === (int) $replacementSeriesId;
    }
}