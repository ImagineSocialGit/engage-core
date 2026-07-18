<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderAttendanceSnapshot;
use App\Modules\Webinars\Models\Webinar;
use Throwable;

class RecordWebinarProviderAttendanceAction
{
    public function __construct(
        private readonly RecordWebinarAttendanceAction $recordWebinarAttendanceAction,
    ) {}

    public function execute(
        WebinarProvider $provider,
        Webinar $webinar,
        string $event,
    ): bool {
        if (! config('webinars.post_event.attendance.enabled', true)) {
            return true;
        }

        $providerKey = $provider->key();
        $wasFinalized = filled(
            data_get($webinar->meta, 'normalized.post_event.attendance_recorded_at')
        );
        $registrationsCount = $webinar->registrations()->count();

        try {
            $snapshot = $this->providerSnapshot(
                $provider->listAttendanceRecords($webinar),
            );
        } catch (Throwable $exception) {
            $this->markProviderFailure(
                webinar: $webinar,
                provider: $providerKey,
                wasFinalized: $wasFinalized,
            );

            throw $exception;
        }

        $attendanceRecords = collect($snapshot->records)->values();

        $this->recordWebinarAttendanceAction->execute(
            webinar: $webinar,
            provider: $providerKey,
            attendanceRecords: $attendanceRecords,
            finalizeMissed: $snapshot->authoritative,
        );

        if ($registrationsCount === 0) {
            $this->storeAttendanceState(
                webinar: $webinar,
                provider: $providerKey,
                snapshot: $snapshot,
                finalized: true,
                finalizationReason: 'no_registrations',
            );

            return true;
        }

        if ($snapshot->authoritative) {
            $this->storeAttendanceState(
                webinar: $webinar,
                provider: $providerKey,
                snapshot: $snapshot,
                finalized: true,
                finalizationReason: 'authoritative_snapshot',
            );

            return true;
        }

        $this->storeAttendanceState(
            webinar: $webinar,
            provider: $providerKey,
            snapshot: $snapshot,
            finalized: $wasFinalized,
        );

        return $wasFinalized;
    }

    private function providerSnapshot(iterable $providerResult): ProviderAttendanceSnapshot
    {
        if ($providerResult instanceof ProviderAttendanceSnapshot) {
            return $providerResult;
        }

        return ProviderAttendanceSnapshot::nonAuthoritative(
            records: $providerResult,
            reason: 'provider_snapshot_authority_unspecified',
        );
    }

    private function markProviderFailure(
        Webinar $webinar,
        string $provider,
        bool $wasFinalized,
    ): void {
        $this->updateAttendanceMeta(
            webinar: $webinar,
            provider: $provider,
            recordCount: null,
            snapshotAuthoritative: false,
            snapshotReason: 'provider_request_failed',
            finalized: $wasFinalized,
        );
    }

    private function storeAttendanceState(
        Webinar $webinar,
        string $provider,
        ProviderAttendanceSnapshot $snapshot,
        bool $finalized,
        ?string $finalizationReason = null,
    ): void {
        $this->updateAttendanceMeta(
            webinar: $webinar,
            provider: $provider,
            recordCount: count($snapshot),
            snapshotAuthoritative: $snapshot->authoritative,
            snapshotReason: $snapshot->reason,
            finalized: $finalized,
            finalizationReason: $finalizationReason,
        );
    }

    private function updateAttendanceMeta(
        Webinar $webinar,
        string $provider,
        ?int $recordCount,
        bool $snapshotAuthoritative,
        ?string $snapshotReason,
        bool $finalized,
        ?string $finalizationReason = null,
    ): void {
        $webinar = $webinar->fresh() ?? $webinar;
        $meta = is_array($webinar->meta) ? $webinar->meta : [];
        $postEvent = data_get($meta, 'normalized.post_event', []);
        $postEvent = is_array($postEvent) ? $postEvent : [];

        $postEvent['attendance_checked_at'] = now()->toIso8601String();
        $postEvent['attendance_provider'] = $provider;
        $postEvent['attendance_record_count'] = $recordCount;
        $postEvent['attendance_snapshot_authoritative'] = $snapshotAuthoritative;
        $postEvent['attendance_ready'] = $finalized;

        if ($snapshotReason === null) {
            unset($postEvent['attendance_snapshot_reason']);
        } else {
            $postEvent['attendance_snapshot_reason'] = $snapshotReason;
        }

        if ($recordCount !== null && $recordCount > 0) {
            $postEvent['attendance_positive_records_observed_at'] = now()->toIso8601String();
        }

        if ($finalized) {
            $postEvent['attendance_recorded_at'] = $postEvent['attendance_recorded_at']
                ?? now()->toIso8601String();

            if ($finalizationReason !== null) {
                $postEvent['attendance_finalization_reason'] = $finalizationReason;
            }
        } else {
            unset(
                $postEvent['attendance_recorded_at'],
                $postEvent['attendance_finalization_reason'],
            );
        }

        data_set($meta, 'normalized.post_event', $postEvent);

        $webinar->forceFill([
            'meta' => $meta,
        ])->save();
    }
}
