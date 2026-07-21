<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\Webinar;
use Illuminate\Database\Eloquent\Collection;

class ResolveWebinarProviderEventTargetAction
{
    public function execute(
        string $provider,
        string $externalWebinarId,
        ?string $providerEventType = null,
        ?string $externalWebinarUuid = null,
    ): ?Webinar {
        $provider = strtolower(trim($provider));
        $externalWebinarId = trim($externalWebinarId);

        if ($provider === '' || $externalWebinarId === '') {
            return null;
        }

        $eventType = WebinarProviderEventType::fromMixed($providerEventType);

        if (filled($providerEventType) && ! $eventType instanceof WebinarProviderEventType) {
            return null;
        }

        $candidates = Webinar::query()
            ->where('platform', $provider)
            ->where('external_id', $externalWebinarId)
            ->when(
                $eventType instanceof WebinarProviderEventType,
                fn ($query) => $query->where('provider_event_type', $eventType->value),
            )
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $uuid = $this->nullableString($externalWebinarUuid);

        if ($uuid !== null) {
            $uuidMatches = $this->matchingUuid($candidates, $uuid);

            if ($uuidMatches->count() === 1) {
                return $uuidMatches->first();
            }

            if ($uuidMatches->count() > 1) {
                return null;
            }
        }

        return $candidates->count() === 1
            ? $candidates->first()
            : null;
    }

    /**
     * @param Collection<int, Webinar> $candidates
     * @return Collection<int, Webinar>
     */
    private function matchingUuid(Collection $candidates, string $uuid): Collection
    {
        return $candidates
            ->filter(fn (Webinar $webinar): bool => $this->providerUuid($webinar) === $uuid)
            ->values();
    }

    private function providerUuid(Webinar $webinar): ?string
    {
        foreach ([
            'provider.data.zoom_uuid',
            'provider.data.uuid',
            'provider.data.raw.uuid',
            'provider.raw.uuid',
            'zoom_uuid',
        ] as $path) {
            $uuid = $this->nullableString(data_get($webinar->meta, $path));

            if ($uuid !== null) {
                return $uuid;
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}