<?php

namespace App\Modules\Reporting\Controllers\Public;

use App\Modules\Reporting\Services\ReportingBrowserRequestClassifier;
use App\Modules\Reporting\Services\ReportingPublicCollectionPolicy;
use App\Support\Reporting\Contracts\ReportingObservationRecorder;
use App\Support\Reporting\Data\ReportingObservationData;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class ReportingObservationController
{
    private const INPUT_KEYS = [
        'event_id',
        'event_key',
        'event_version',
        'occurred_at',
        'surface',
        'path',
        'properties',
        'session_token',
        'referrer_host',
        'query',
    ];

    public function __invoke(
        Request $request,
        ReportingObservationRecorder $recorder,
        ReportingBrowserRequestClassifier $classifier,
        ReportingPublicCollectionPolicy $collectionPolicy,
    ): JsonResponse {
        if (! $request->isJson()) {
            return $this->rejected('json_required', 415);
        }

        if (strlen($request->getContent()) > $this->maximumPayloadBytes()) {
            return $this->rejected('payload_too_large', 413);
        }

        $input = $request->all();

        if (array_diff(array_keys($input), self::INPUT_KEYS) !== []) {
            return $this->rejected('invalid_payload', 422);
        }

        $validator = Validator::make($input, [
            'event_id' => ['required', 'uuid'],
            'event_key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'event_version' => ['required', 'integer', 'min:1', 'max:65535'],
            'occurred_at' => ['required', 'string', 'max:64'],
            'surface' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'path' => ['required', 'string', 'max:512'],
            'properties' => ['nullable', 'array', 'max:16'],
            'session_token' => ['nullable', 'string', 'max:255'],
            'referrer_host' => ['nullable', 'string', 'max:255'],
            'query' => ['nullable', 'array', 'max:16'],
        ]);

        if ($validator->fails()) {
            return $this->rejected('invalid_payload', 422);
        }

        $validated = $validator->validated();

        if (! $this->validPath((string) $validated['path'])) {
            return $this->rejected('invalid_payload', 422);
        }

        if (! $collectionPolicy->allows(
            request: $request,
            eventKey: (string) $validated['event_key'],
            eventVersion: (int) $validated['event_version'],
            surface: (string) $validated['surface'],
        )) {
            return $this->rejected('not_available', 404);
        }

        try {
            $occurredAt = CarbonImmutable::parse((string) $validated['occurred_at'])->utc();
            $classification = $classifier->classify($request);
            $query = $collectionPolicy->normalizeQuery(
                is_array($validated['query'] ?? null) ? $validated['query'] : [],
            );
        } catch (InvalidArgumentException) {
            return $this->rejected('invalid_observation', 422);
        } catch (LogicException) {
            return $this->rejected('not_available', 503);
        } catch (Throwable $exception) {
            throw $exception;
        }

        try {
            $result = $recorder->record(new ReportingObservationData(
                eventId: (string) $validated['event_id'],
                eventKey: (string) $validated['event_key'],
                eventVersion: (int) $validated['event_version'],
                source: 'browser',
                occurredAt: $occurredAt,
                host: $request->getHost(),
                surface: (string) $validated['surface'],
                path: (string) $validated['path'],
                properties: is_array($validated['properties'] ?? null)
                    ? $validated['properties']
                    : [],
                sessionToken: isset($validated['session_token'])
                    ? (string) $validated['session_token']
                    : null,
                referrer: isset($validated['referrer_host'])
                    ? (string) $validated['referrer_host']
                    : null,
                query: $query,
                trafficClass: $classification->trafficClass,
                classifierKey: $classification->classifierKey,
                classifierVersion: $classification->classifierVersion,
                classificationReasons: $classification->reasons,
                deviceClass: $classification->deviceClass,
                browserFamily: $classification->browserFamily,
                osFamily: $classification->osFamily,
            ));
        } catch (InvalidArgumentException) {
            return $this->rejected('invalid_observation', 422);
        } catch (LogicException) {
            return $this->rejected('event_conflict', 409);
        } catch (Throwable $exception) {
            throw $exception;
        }

        return response()->json([
            'status' => $result->status,
            'event_id' => $result->eventId,
        ], 202);
    }

    private function maximumPayloadBytes(): int
    {
        return min(
            8192,
            max(256, (int) config('reporting.ingestion.max_payload_bytes', 8192)),
        );
    }

    private function validPath(string $path): bool
    {
        return str_starts_with($path, '/')
            && ! str_contains($path, '?')
            && ! str_contains($path, '#')
            && ! str_contains($path, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }

    private function rejected(string $code, int $status): JsonResponse
    {
        return response()->json([
            'status' => 'rejected',
            'code' => $code,
        ], $status);
    }
}