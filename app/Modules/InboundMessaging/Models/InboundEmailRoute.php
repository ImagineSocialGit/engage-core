<?php

namespace App\Modules\InboundMessaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InboundEmailRoute extends Model
{
    protected $fillable = [
        'key',
        'local_part',
        'label',
        'source',
        'context_key',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}