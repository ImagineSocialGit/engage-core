<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class CampaignAllocationEnrollment extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'contact_id',
        'campaign_id',
        'source_type',
        'source_id',
        'start_message_step_key',
        'status',
        'dedupe_key',
        'started_at',
        'completed_at',
        'cancelled_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'contact_id' => 'integer',
            'campaign_id' => 'integer',
            'source_id' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CampaignAllocationAssignment::class);
    }

    public function latestAssignment(): HasOne
    {
        return $this->hasOne(CampaignAllocationAssignment::class)->latestOfMany();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}