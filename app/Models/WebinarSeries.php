<?php

namespace App\Models;

use App\Actions\Caching\FlushWebinarCachesAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WebinarSeries extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'status',
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
        return $this->hasMany(Webinar::class, 'series_id');
    }
}