<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class ReportingProjectionCheckpoint extends Model
{
    protected $fillable = [
        'projector_key',
        'projector_version',
        'cursor',
        'window_start',
        'window_end',
        'projected_through',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'projector_version' => 'integer',
            'window_start' => 'immutable_datetime',
            'window_end' => 'immutable_datetime',
            'projected_through' => 'immutable_datetime',
            'meta' => 'array',
        ];
    }
}