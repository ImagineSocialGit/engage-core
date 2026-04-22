<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadTag extends Model
{
    protected $fillable = [
        'lead_id',
        'tag',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}