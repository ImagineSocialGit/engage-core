<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebinarSeries extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $series): void {
            if (($series->isDirty('title') && blank($series->slug)) || blank($series->slug)) {
                $series->slug = Str::slug($series->title);
            }
        });
    }

    public function webinars(): HasMany
    {
        return $this->hasMany(Webinar::class, 'series_id');
    }
}