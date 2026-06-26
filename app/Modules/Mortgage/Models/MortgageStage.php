<?php

namespace App\Modules\Mortgage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MortgageStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'sort_order',
    ];

    public function mortgageProfiles(): HasMany
    {
        return $this->hasMany(
            ContactMortgageProfile::class,
            'mortgage_stage_id',
        );
    }
}