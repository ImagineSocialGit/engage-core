<?php

namespace App\Actions\Webinars;

use App\Models\Webinar;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateWebinarCopiesAction
{
    public function execute(Webinar $sourceWebinar, array $copies): Collection
    {
        return DB::transaction(function () use ($sourceWebinar, $copies) {
            $created = collect();

            foreach ($copies as $copy) {
                $timezone = $sourceWebinar->timezone ?: config('app.timezone', 'America/Chicago');

                $startsAtLocal = Carbon::parse($copy['starts_at'], $timezone);
                $startsAtUtc = $startsAtLocal->copy()->utc();

                $endsAtUtc = $this->resolveEndsAt(
                    sourceWebinar: $sourceWebinar,
                    copy: $copy,
                    timezone: $timezone,
                    startsAtLocal: $startsAtLocal,
                );

                $title = filled($copy['title'] ?? null)
                    ? trim($copy['title'])
                    : $sourceWebinar->title;

                $externalId = preg_replace('/\D/', '', (string) $copy['external_id']);

                $slug = filled($copy['slug'] ?? null)
                    ? Str::slug($copy['slug'])
                    : $this->generateSlug($title, $startsAtLocal);

                $created->push(Webinar::query()->create([
                    'title' => $title,
                    'slug' => $slug,
                    'series_id' => $sourceWebinar->series_id,
                    'status' => $sourceWebinar->status,
                    'join_url' => $sourceWebinar->join_url,
                    'registration_url' => $sourceWebinar->registration_url,
                    'platform' => $sourceWebinar->platform,
                    'external_id' => $externalId,
                    'host_account_key' => $sourceWebinar->host_account_key,
                    'starts_at' => $startsAtUtc,
                    'timezone' => $timezone,
                    'ends_at' => $endsAtUtc,
                    'description' => $sourceWebinar->description,
                    'meta' => $sourceWebinar->meta,
                    'provider_settings' => $sourceWebinar->provider_settings,
                ]));
            }

            return $created;
        });
    }

    protected function resolveEndsAt(
        Webinar $sourceWebinar,
        array $copy,
        string $timezone,
        Carbon $startsAtLocal,
    ): ?Carbon {
        if (filled($copy['ends_at'] ?? null)) {
            return Carbon::parse($copy['ends_at'], $timezone)->utc();
        }

        if (! $sourceWebinar->starts_at || ! $sourceWebinar->ends_at) {
            return null;
        }

        $durationInSeconds = $sourceWebinar->ends_at->diffInSeconds($sourceWebinar->starts_at);

        return $startsAtLocal->copy()->addSeconds($durationInSeconds)->utc();
    }

    protected function generateSlug(string $title, Carbon $startsAtLocal): string
    {
        return Str::slug(
            $title . '-' . $startsAtLocal->format('Y-m-d-gia')
        );
    }
}