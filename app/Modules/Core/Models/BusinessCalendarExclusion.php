<?php

namespace App\Modules\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessCalendarExclusion extends Model
{
    public const RECURRENCE_ANNUAL = 'annual';
    public const RECURRENCE_ONCE = 'once';

    public const RECURRENCES = [
        self::RECURRENCE_ANNUAL,
        self::RECURRENCE_ONCE,
    ];

    protected $fillable = [
        'business_calendar_id',
        'key',
        'name',
        'recurrence',
        'exact_date',
        'month',
        'day',
    ];

    protected function casts(): array
    {
        return [
            'exact_date' => 'immutable_date',
            'month' => 'integer',
            'day' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BusinessCalendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BusinessCalendar::class, 'business_calendar_id');
    }

    public function matches(CarbonInterface $date): bool
    {
        if ($this->recurrence === self::RECURRENCE_ONCE) {
            return $this->exact_date?->toDateString() === $date->toDateString();
        }

        return $this->recurrence === self::RECURRENCE_ANNUAL
            && $this->month === $date->month
            && $this->day === $date->day;
    }
}