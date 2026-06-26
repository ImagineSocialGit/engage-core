<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactStatus extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'category',
        'is_core',
        'is_active',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'is_core' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCore(Builder $query): Builder
    {
        return $query->where('is_core', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}