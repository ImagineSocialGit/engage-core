<?php

namespace App\Models;

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
    }

    public function webinars(): HasMany
    {
        return $this->hasMany(Webinar::class, 'series_id');
    }

    public function nextUpcomingWebinar(): ?\App\Models\Webinar
    {
        return $this->webinars()
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->first();
    }
}