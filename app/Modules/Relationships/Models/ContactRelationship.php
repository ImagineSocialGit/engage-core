<?php

namespace App\Modules\Relationships\Models;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactRelationship extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'relationship_key',
        'stage_key',
        'source',
        'subsource',
        'is_active',
        'started_at',
        'ended_at',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'meta' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForRelationship(Builder $query, string $relationshipKey): Builder
    {
        return $query->where('relationship_key', trim($relationshipKey));
    }
}