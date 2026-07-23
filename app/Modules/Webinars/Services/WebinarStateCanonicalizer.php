<?php

namespace App\Modules\Webinars\Services;

final class WebinarStateCanonicalizer
{
    /**
     * Canonicalize one complete WebinarRegistration state array for import.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function registration(array $state): array
    {
        if (is_array($state['meta'] ?? null)) {
            $state['meta'] = $this->registrationMeta($state['meta']);
        }

        return $state;
    }

    /**
     * Canonicalize one complete Webinar state array for import.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function webinar(array $state): array
    {
        if (is_array($state['meta'] ?? null)) {
            $state['meta'] = $this->webinarMeta($state['meta']);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function registrationMeta(array $meta): array
    {
        if (array_key_exists('provider', $meta)) {
            $provider = is_array($meta['provider'])
                ? $this->registrationProvider($meta['provider'])
                : [];

            if ($provider === []) {
                unset($meta['provider']);
            } else {
                $meta['provider'] = $provider;
            }
        }

        if (array_key_exists('attendance', $meta)) {
            $attendance = is_array($meta['attendance'])
                ? $this->attendance($meta['attendance'])
                : [];

            if ($attendance === []) {
                unset($meta['attendance']);
            } else {
                $meta['attendance'] = $attendance;
            }
        }

        if (array_key_exists('provider_sync', $meta)) {
            $providerSync = is_array($meta['provider_sync'])
                ? $this->providerSync($meta['provider_sync'])
                : [];

            if ($providerSync === []) {
                unset($meta['provider_sync']);
            } else {
                $meta['provider_sync'] = $providerSync;
            }
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $provider
     * @return array<string, mixed>
     */
    public function registrationProvider(array $provider): array
    {
        $data = $this->arrayValue($provider['data'] ?? null);
        $providerRaw = $this->arrayValue($provider['raw'] ?? null);
        $dataRaw = $this->arrayValue($data['raw'] ?? null);

        return $this->withoutNulls([
            'key' => $this->stringValue(
                $provider['key']
                    ?? $provider['name']
                    ?? $provider['provider']
                    ?? $data['key']
                    ?? $data['name']
                    ?? null,
            ),
            'registrant_id' => $this->stringValue(
                $provider['registrant_id']
                    ?? $provider['id']
                    ?? $data['registrant_id']
                    ?? $data['id']
                    ?? $providerRaw['registrant_id']
                    ?? $providerRaw['id']
                    ?? $dataRaw['registrant_id']
                    ?? $dataRaw['id']
                    ?? null,
            ),
            'join_url' => $this->stringValue(
                $provider['join_url']
                    ?? $data['join_url']
                    ?? $providerRaw['join_url']
                    ?? $dataRaw['join_url']
                    ?? null,
            ),
            'occurrence_id' => $this->stringValue(
                $provider['occurrence_id']
                    ?? $data['occurrence_id']
                    ?? $providerRaw['occurrence_id']
                    ?? $dataRaw['occurrence_id']
                    ?? null,
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $attendance
     * @return array<string, mixed>
     */
    public function attendance(array $attendance): array
    {
        $raw = $this->arrayValue($attendance['raw'] ?? null);
        $providerRegistrantId = $this->stringValue(
            $attendance['provider_registrant_id']
                ?? $attendance['registrant_id']
                ?? $raw['registrant_id']
                ?? $raw['id']
                ?? null,
        );
        $matchedBy = $this->stringValue(
            $attendance['matched_by'] ?? null,
        );

        if ($matchedBy === null && $providerRegistrantId !== null) {
            $matchedBy = 'provider_registrant_id';
        }

        if (
            $matchedBy === null
            && $this->stringValue(
                $raw['email']
                    ?? $raw['user_email']
                    ?? null,
            ) !== null
        ) {
            $matchedBy = 'email';
        }

        return $this->withoutNulls([
            'provider' => $this->stringValue(
                $attendance['provider']
                    ?? $raw['provider']
                    ?? null,
            ),
            'status' => $this->stringValue(
                $attendance['status']
                    ?? $raw['status']
                    ?? null,
            ),
            'duration' => $this->integerValue(
                $attendance['duration']
                    ?? $raw['duration']
                    ?? null,
            ),
            'join_time' => $this->stringValue(
                $attendance['join_time']
                    ?? $raw['join_time']
                    ?? null,
            ),
            'leave_time' => $this->stringValue(
                $attendance['leave_time']
                    ?? $raw['leave_time']
                    ?? null,
            ),
            'recorded_at' => $this->stringValue(
                $attendance['recorded_at'] ?? null,
            ),
            'provider_registrant_id' => $providerRegistrantId,
            'matched_by' => $matchedBy,
            'source' => $this->stringValue(
                $attendance['source']
                    ?? $raw['source']
                    ?? null,
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function providerSync(array $state): array
    {
        $resolution = $this->arrayValue(
            $state['reconciliation_resolution'] ?? null,
        );
        $canonical = $this->withoutNulls([
            'status' => $this->stringValue($state['status'] ?? null),
            'provider' => $this->stringValue($state['provider'] ?? null),
            'attempts' => $this->integerValue($state['attempts'] ?? null),
            'first_attempted_at' => $this->stringValue(
                $state['first_attempted_at'] ?? null,
            ),
            'last_attempted_at' => $this->stringValue(
                $state['last_attempted_at'] ?? null,
            ),
            'claim_started_at' => $this->stringValue(
                $state['claim_started_at'] ?? null,
            ),
            'submission_started_at' => $this->stringValue(
                $state['submission_started_at'] ?? null,
            ),
            'succeeded_at' => $this->stringValue(
                $state['succeeded_at'] ?? null,
            ),
            'failed_at' => $this->stringValue(
                $state['failed_at'] ?? null,
            ),
            'reconciliation_required_at' => $this->stringValue(
                $state['reconciliation_required_at'] ?? null,
            ),
            'failure_reason' => $this->stringValue(
                $state['failure_reason'] ?? null,
            ),
            'last_error_class' => $this->stringValue(
                $state['last_error_class'] ?? null,
            ),
            'last_error_code' => $this->stringValue(
                $state['last_error_code'] ?? null,
            ),
            'operator_retry_authorized_at' => $this->stringValue(
                $state['operator_retry_authorized_at'] ?? null,
            ),
            'operator_retry_authorized_by' => $this->integerValue(
                $state['operator_retry_authorized_by'] ?? null,
            ),
            'resubmission_authorized_at' => $this->stringValue(
                $state['resubmission_authorized_at'] ?? null,
            ),
            'resubmission_authorized_by' => $this->integerValue(
                $state['resubmission_authorized_by'] ?? null,
            ),
        ]);
        $canonicalResolution = $this->withoutNulls([
            'decision' => $this->stringValue($resolution['decision'] ?? null),
            'resolved_at' => $this->stringValue(
                $resolution['resolved_at'] ?? null,
            ),
            'resolved_by' => $this->integerValue(
                $resolution['resolved_by'] ?? null,
            ),
            'notes' => $this->stringValue($resolution['notes'] ?? null),
            'prior_failure_reason' => $this->stringValue(
                $resolution['prior_failure_reason'] ?? null,
            ),
        ]);

        if ($canonicalResolution !== []) {
            $canonical['reconciliation_resolution'] = $canonicalResolution;
        }

        return $canonical;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function playbackResolution(array $state): array
    {
        $recording = $this->arrayValue($state['recording'] ?? null);
        $raw = $this->arrayValue(
            $state['raw']
                ?? $recording['raw']
                ?? null,
        );

        return $this->withoutNulls([
            'playback_resolved_at' => $this->stringValue(
                $state['playback_resolved_at']
                    ?? $state['resolved_at']
                    ?? $recording['resolved_at']
                    ?? $raw['resolved_at']
                    ?? null,
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function webinarMeta(array $meta): array
    {
        $provider = $this->arrayValue($meta['provider'] ?? null);
        $normalized = $this->arrayValue($meta['normalized'] ?? null);
        $postEvent = $this->arrayValue($normalized['post_event'] ?? null);
        $legacyRecording = $this->arrayValue($meta['recording'] ?? null);
        $legacyRecordingRaw = $this->arrayValue(
            $legacyRecording['raw'] ?? null,
        );
        $playbackResolution = $this->playbackResolution([
            'playback_resolved_at' => $postEvent['playback_resolved_at'] ?? null,
            'resolved_at' => $this->recordingResolvedAt($provider)
                ?? $legacyRecording['resolved_at']
                ?? $legacyRecordingRaw['resolved_at']
                ?? null,
        ]);

        $provider = $this->webinarProvider(
            provider: $provider,
            legacyZoomUuid: $meta['zoom_uuid'] ?? null,
        );

        if ($provider === []) {
            unset($meta['provider']);
        } else {
            $meta['provider'] = $provider;
        }

        unset($meta['recording'], $meta['zoom_uuid']);

        if ($playbackResolution !== []) {
            $postEvent = array_replace($postEvent, $playbackResolution);
            $normalized['post_event'] = $postEvent;
            $meta['normalized'] = $normalized;
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $provider
     * @return array<string, mixed>
     */
    private function webinarProvider(
        array $provider,
        mixed $legacyZoomUuid,
    ): array {
        $data = $this->arrayValue($provider['data'] ?? null);
        $raw = $this->arrayValue($data['raw'] ?? null);
        $key = $this->stringValue(
            $provider['key']
                ?? $provider['name']
                ?? null,
        );

        if ($key === null) {
            foreach ($provider as $candidateKey => $candidateValue) {
                if (
                    is_string($candidateKey)
                    && is_array($candidateValue)
                    && ! in_array($candidateKey, ['data', 'recording', 'raw'], true)
                ) {
                    $key = $this->stringValue($candidateKey);
                    break;
                }
            }
        }

        $zoomUuid = $this->stringValue(
            $data['zoom_uuid']
                ?? $data['uuid']
                ?? $raw['uuid']
                ?? $provider['zoom_uuid']
                ?? $provider['uuid']
                ?? $legacyZoomUuid,
        );

        if ($key === null && $zoomUuid !== null) {
            $key = 'zoom';
        }

        $canonicalData = $this->withoutNulls([
            'zoom_uuid' => $zoomUuid,
        ]);
        $canonical = $this->withoutNulls([
            'key' => $key,
        ]);

        if ($canonicalData !== []) {
            $canonical['data'] = $canonicalData;
        }

        return $canonical;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function recordingResolvedAt(array $state): ?string
    {
        if (is_array($state['recording'] ?? null)) {
            $recording = $state['recording'];
            $recordingRaw = $this->arrayValue($recording['raw'] ?? null);
            $resolvedAt = $this->stringValue(
                $recording['resolved_at']
                    ?? $recordingRaw['resolved_at']
                    ?? null,
            );

            if ($resolvedAt !== null) {
                return $resolvedAt;
            }
        }

        foreach ($state as $value) {
            if (! is_array($value)) {
                continue;
            }

            $resolvedAt = $this->recordingResolvedAt($value);

            if ($resolvedAt !== null) {
                return $resolvedAt;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function integerValue(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function withoutNulls(array $values): array
    {
        return array_filter(
            $values,
            static fn (mixed $value): bool => $value !== null,
        );
    }
}