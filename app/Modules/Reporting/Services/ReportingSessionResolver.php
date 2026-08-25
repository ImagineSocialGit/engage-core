<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Data\NormalizedReportingAttribution;
use App\Modules\Reporting\Models\ReportingSession;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ReportingSessionResolver
{
    /**
     * @param array<int, string> $classificationReasons
     */
    public function resolve(
        ?string $sessionToken,
        string $host,
        string $surface,
        NormalizedReportingAttribution $attribution,
        CarbonImmutable $receivedAt,
        string $trafficClass = 'unknown',
        ?string $classifierKey = null,
        ?int $classifierVersion = null,
        array $classificationReasons = [],
        ?string $deviceClass = null,
        ?string $browserFamily = null,
        ?string $osFamily = null,
    ): ?ReportingSession {
        $sessionToken = $this->normalizeToken($sessionToken);

        if ($sessionToken === null) {
            return null;
        }

        $tokenHash = hash('sha256', $sessionToken);
        $inactivityBoundary = $receivedAt->subMinutes($this->inactivityMinutes());

        $session = ReportingSession::query()
            ->where('token_hash', $tokenHash)
            ->where('host', $host)
            ->where('absolute_expires_at', '>', $receivedAt)
            ->where('last_seen_at', '>', $inactivityBoundary)
            ->orderByDesc('last_seen_at')
            ->lockForUpdate()
            ->first();

        if ($session instanceof ReportingSession) {
            $updates = [];

            if ($session->last_seen_at === null || $receivedAt->greaterThan($session->last_seen_at)) {
                $updates['last_seen_at'] = $receivedAt;
            }

            if ($this->classificationRank($trafficClass) > $this->classificationRank($session->traffic_class)) {
                $updates = [
                    ...$updates,
                    'traffic_class' => $trafficClass,
                    'classifier_key' => $classifierKey,
                    'classifier_version' => $classifierVersion,
                    'classification_reasons' => $classificationReasons !== []
                        ? $classificationReasons
                        : null,
                    'device_class' => $deviceClass ?? $session->device_class,
                    'browser_family' => $browserFamily ?? $session->browser_family,
                    'os_family' => $osFamily ?? $session->os_family,
                ];
            }

            if ($updates !== []) {
                $session->forceFill($updates)->save();
            }

            return $session->refresh();
        }

        return ReportingSession::query()->create([
            'token_hash' => $tokenHash,
            'host' => $host,
            'surface' => $surface,
            'started_at' => $receivedAt,
            'last_seen_at' => $receivedAt,
            'absolute_expires_at' => $receivedAt->addMinutes($this->absoluteMinutes()),
            'landing_path' => $attribution->path,
            'referrer_host' => $attribution->referrerHost,
            'utm_source' => $attribution->utmSource,
            'utm_medium' => $attribution->utmMedium,
            'utm_campaign' => $attribution->utmCampaign,
            'utm_content' => $attribution->utmContent,
            'utm_term' => $attribution->utmTerm,
            'external_platform' => $attribution->externalPlatform,
            'external_campaign_id' => $attribution->externalCampaignId,
            'external_group_id' => $attribution->externalGroupId,
            'external_creative_id' => $attribution->externalCreativeId,
            'external_placement' => $attribution->externalPlacement,
            'click_id_hashes' => $attribution->clickIdHashes !== []
                ? $attribution->clickIdHashes
                : null,
            'traffic_class' => $trafficClass,
            'classifier_key' => $classifierKey,
            'classifier_version' => $classifierVersion,
            'classification_reasons' => $classificationReasons !== []
                ? $classificationReasons
                : null,
            'device_class' => $deviceClass,
            'browser_family' => $browserFamily,
            'os_family' => $osFamily,
        ]);
    }

    private function classificationRank(mixed $trafficClass): int
    {
        return match ($trafficClass) {
            'likely_automated' => 2,
            'likely_human' => 1,
            default => 0,
        };
    }

    public function tokenHash(?string $sessionToken): ?string
    {
        $sessionToken = $this->normalizeToken($sessionToken);

        return $sessionToken !== null
            ? hash('sha256', $sessionToken)
            : null;
    }

    private function normalizeToken(?string $sessionToken): ?string
    {
        if ($sessionToken === null) {
            return null;
        }

        $sessionToken = trim($sessionToken);

        if ($sessionToken === '') {
            return null;
        }

        $length = strlen($sessionToken);

        if ($length < $this->tokenMinLength()
            || $length > $this->tokenMaxLength()
            || preg_match('/[\x00-\x20\x7F]/', $sessionToken) === 1
        ) {
            throw new InvalidArgumentException('Reporting session token is invalid.');
        }

        return $sessionToken;
    }

    private function inactivityMinutes(): int
    {
        return min(30, max(1, (int) config('reporting.session.inactivity_minutes', 30)));
    }

    private function absoluteMinutes(): int
    {
        return min(240, max(1, (int) config('reporting.session.absolute_minutes', 240)));
    }

    private function tokenMinLength(): int
    {
        return min(255, max(16, (int) config('reporting.session.token_min_length', 16)));
    }

    private function tokenMaxLength(): int
    {
        return min(255, max($this->tokenMinLength(), (int) config('reporting.session.token_max_length', 255)));
    }
}