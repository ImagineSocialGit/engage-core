<?php

namespace App\Modules\Mortgage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MortgageStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'category',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function loans(): HasMany
    {
        return $this->hasMany(
            MortgageLoan::class,
            'mortgage_stage_id',
        );
    }
}