<?php

namespace App\Modules\Webinars\Models;

use Database\Factories\WebinarSeriesFactory;
use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WebinarSeries extends Model
{
    use HasFactory;

    protected static function newFactory(): WebinarSeriesFactory
    {
        return WebinarSeriesFactory::new();
    }

    protected $fillable = [
        'title',
        'slug',
        'status',
        'webinar_schedule_profile_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($series) {
            if (empty($series->slug)) {
                $series->slug = Str::slug($series->title);
            }
        });

        static::saved(function (WebinarSeries $series): void {
            if (! $series->wasChanged([
                'slug',
                'title',
                'status',
        'webinar_schedule_profile_id',
                'meta',
            ])) {
                return;
            }

            app(FlushWebinarCachesAction::class)
                ->handle(seriesSlug: $series->slug);
        });

        static::deleted(function (WebinarSeries $series): void {
            app(FlushWebinarCachesAction::class)
                ->handle(seriesSlug: $series->slug);
        });
    }

    public function webinars(): HasMany
    {
        return $this->hasMany(Webinar::class, 'webinar_series_id');
    }

    public function webinarScheduleProfile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WebinarScheduleProfile::class);
    }
}
