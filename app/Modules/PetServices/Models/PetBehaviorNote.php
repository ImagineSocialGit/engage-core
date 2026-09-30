<?php

namespace App\Modules\PetServices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PetBehaviorNote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'pet_id',
        'category',
        'severity',
        'note',
        'observed_at',
        'source',
        'meta',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}