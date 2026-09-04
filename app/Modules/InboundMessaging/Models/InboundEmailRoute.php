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
        'contact_extraction_enabled',
        'contact_extraction_definition',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'contact_extraction_enabled' => 'boolean',
            'contact_extraction_definition' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}