<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_series_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_series_id');
            $table->string('key', 100);
            $table->string('name', 100);
            $table->string('public_slug')->unique('webinar_variant_public_slug_unique');
            $table->string('timezone', 100);
            $table->string('platform', 64)->default('zoom');
            $table->string('provider_event_type', 32)->default('webinar');
            $table->string('provider_match_title');
            $table->string('status', 32)->default('active')->index();
            $table->boolean('is_default')->default(false)->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['webinar_series_id', 'key'],
                'webinar_variant_series_key_unique',
            );
            $table->foreign(
                'webinar_series_id',
                'webinar_variant_series_fk',
            )->references('id')->on('webinar_series')->cascadeOnDelete();
        });

        Schema::table('webinars', function (Blueprint $table): void {
            $table->unsignedBigInteger('webinar_series_variant_id')
                ->nullable()
                ->after('webinar_series_id');
            $table->index(
                ['webinar_series_variant_id', 'starts_at'],
                'webinars_variant_starts_idx',
            );
            $table->foreign(
                'webinar_series_variant_id',
                'webinars_variant_fk',
            )->references('id')->on('webinar_series_variants')->nullOnDelete();
        });

        Schema::table('webinar_waitlist_signups', function (Blueprint $table): void {
            $table->unsignedBigInteger('webinar_series_variant_id')
                ->nullable()
                ->after('webinar_series_id');
            $table->index(
                ['webinar_series_variant_id', 'notification_mode'],
                'webinar_waitlist_variant_mode_idx',
            );
            $table->foreign(
                'webinar_series_variant_id',
                'webinar_waitlist_variant_fk',
            )->references('id')->on('webinar_series_variants')->nullOnDelete();
        });

        $fallbackTimezone = $this->configuredTimezone();

        DB::table('webinar_series')
            ->orderBy('id')
            ->get()
            ->each(function (object $series) use ($fallbackTimezone): void {
                [$timezone, $timezoneSource] = $this->resolveLegacyTimezone(
                    (int) $series->id,
                    $fallbackTimezone,
                );

                $slug = is_string($series->slug) && trim($series->slug) !== ''
                    ? trim($series->slug)
                    : 'webinar-series-'.$series->id;
                $title = is_string($series->title) && trim($series->title) !== ''
                    ? trim($series->title)
                    : 'Webinar Series '.$series->id;
                $provider = is_string($series->platform ?? null)
                    && trim((string) $series->platform) !== ''
                        ? strtolower(trim((string) $series->platform))
                        : 'zoom';
                $eventType = is_string($series->provider_event_type ?? null)
                    && trim((string) $series->provider_event_type) !== ''
                        ? strtolower(trim((string) $series->provider_event_type))
                        : 'webinar';
                $now = now();

                $variantId = DB::table('webinar_series_variants')->insertGetId([
                    'webinar_series_id' => $series->id,
                    'key' => 'primary',
                    'name' => $this->marketLabel($timezone),
                    'public_slug' => $slug,
                    'timezone' => $timezone,
                    'platform' => $provider,
                    'provider_event_type' => $eventType,
                    'provider_match_title' => $title,
                    'status' => 'active',
                    'is_default' => true,
                    'meta' => json_encode([
                        'compatibility' => [
                            'inherited_series_slug' => true,
                            'original_public_slug' => $slug,
                            'initial_timezone_source' => $timezoneSource,
                        ],
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $series->created_at ?? $now,
                    'updated_at' => $series->updated_at ?? $now,
                ]);

                DB::table('webinars')
                    ->where('webinar_series_id', $series->id)
                    ->whereNull('webinar_series_variant_id')
                    ->update(['webinar_series_variant_id' => $variantId]);

                DB::table('webinar_waitlist_signups')
                    ->where('webinar_series_id', $series->id)
                    ->whereNull('webinar_series_variant_id')
                    ->update(['webinar_series_variant_id' => $variantId]);
            });

        Schema::table('webinar_waitlist_signups', function (Blueprint $table): void {
            $table->dropUnique(
                'webinar_waitlist_signups_webinar_series_id_contact_id_unique',
            );
            $table->unique(
                [
                    'webinar_series_id',
                    'webinar_series_variant_id',
                    'contact_id',
                ],
                'webinar_waitlist_series_variant_contact_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('webinar_waitlist_signups', function (Blueprint $table): void {
            $table->dropUnique('webinar_waitlist_series_variant_contact_unique');
            $table->unique(
                ['webinar_series_id', 'contact_id'],
                'webinar_waitlist_signups_webinar_series_id_contact_id_unique',
            );
        });

        Schema::table('webinar_waitlist_signups', function (Blueprint $table): void {
            $table->dropForeign('webinar_waitlist_variant_fk');
            $table->dropIndex('webinar_waitlist_variant_mode_idx');
            $table->dropColumn('webinar_series_variant_id');
        });

        Schema::table('webinars', function (Blueprint $table): void {
            $table->dropForeign('webinars_variant_fk');
            $table->dropIndex('webinars_variant_starts_idx');
            $table->dropColumn('webinar_series_variant_id');
        });

        Schema::dropIfExists('webinar_series_variants');
    }

    /** @return array{0: string, 1: string} */
    private function resolveLegacyTimezone(int $seriesId, string $fallback): array
    {
        if (Schema::hasTable('webinar_schedule_changes')) {
            $completedChangeTimezone = DB::table('webinar_schedule_changes as changes')
                ->join('webinars', 'webinars.id', '=', 'changes.webinar_id')
                ->where('webinars.webinar_series_id', $seriesId)
                ->where('changes.status', 'completed')
                ->whereNotNull('changes.current_timezone')
                ->where('changes.current_timezone', '!=', '')
                ->orderByDesc('changes.completed_at')
                ->orderByDesc('changes.id')
                ->value('changes.current_timezone');

            if (($timezone = $this->validTimezone($completedChangeTimezone)) !== null) {
                return [$timezone, 'completed_schedule_change'];
            }
        }

        $nearestUpcomingTimezone = DB::table('webinars')
            ->where('webinar_series_id', $seriesId)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now())
            ->whereNotNull('timezone')
            ->where('timezone', '!=', '')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->value('timezone');

        if (($timezone = $this->validTimezone($nearestUpcomingTimezone)) !== null) {
            return [$timezone, 'nearest_upcoming_occurrence'];
        }

        $latestHistoricalTimezone = DB::table('webinars')
            ->where('webinar_series_id', $seriesId)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<', now())
            ->whereNotNull('timezone')
            ->where('timezone', '!=', '')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->value('timezone');

        if (($timezone = $this->validTimezone($latestHistoricalTimezone)) !== null) {
            return [$timezone, 'latest_historical_occurrence'];
        }

        $storedTimezone = DB::table('webinars')
            ->where('webinar_series_id', $seriesId)
            ->whereNotNull('timezone')
            ->where('timezone', '!=', '')
            ->orderByDesc('id')
            ->value('timezone');

        if (($timezone = $this->validTimezone($storedTimezone)) !== null) {
            return [$timezone, 'stored_occurrence'];
        }

        return [$fallback, 'configured_fallback'];
    }

    private function configuredTimezone(): string
    {
        foreach ([config('client.timezone'), config('app.timezone'), 'UTC'] as $timezone) {
            if (($valid = $this->validTimezone($timezone)) !== null) {
                return $valid;
            }
        }

        return 'UTC';
    }

    private function validTimezone(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $timezone = trim($value);

        return $timezone !== ''
            && in_array($timezone, timezone_identifiers_list(), true)
                ? $timezone
                : null;
    }

    private function marketLabel(string $timezone): string
    {
        return match ($timezone) {
            'America/New_York' => 'Eastern',
            'America/Chicago' => 'Central',
            'America/Denver' => 'Mountain',
            'America/Los_Angeles' => 'Pacific',
            default => Str::of($timezone)->afterLast('/')->replace('_', ' ')->toString() ?: 'Primary',
        };
    }
};