<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessCalendar extends Model
{
    protected $fillable = [
        'key',
        'name',
        'skipped_weekdays',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'skipped_weekdays' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<BusinessCalendarExclusion, $this>
     */
    public function exclusions(): HasMany
    {
        return $this->hasMany(BusinessCalendarExclusion::class)
            ->orderByRaw("CASE WHEN recurrence = 'annual' THEN 0 ELSE 1 END")
            ->orderBy('month')
            ->orderBy('day')
            ->orderBy('exact_date')
            ->orderBy('name');
    }

    public function scopeDefaultCalendar(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /** @return array<int, int> */
    public function skippedWeekdays(): array
    {
        return collect($this->skipped_weekdays ?? [])
            ->filter(fn (mixed $day): bool => is_numeric($day))
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}