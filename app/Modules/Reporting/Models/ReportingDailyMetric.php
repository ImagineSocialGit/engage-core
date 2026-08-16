<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class ReportingDailyMetric extends Model
{
    protected $fillable = [
        'metric_date',
        'metric_key',
        'metric_version',
        'dimension_hash',
        'dimensions',
        'numerator',
        'denominator',
        'projected_through',
    ];

    protected function casts(): array
    {
        return [
            'metric_date' => 'immutable_date',
            'metric_version' => 'integer',
            'dimensions' => 'array',
            'numerator' => 'integer',
            'denominator' => 'integer',
            'projected_through' => 'immutable_datetime',
        ];
    }
}