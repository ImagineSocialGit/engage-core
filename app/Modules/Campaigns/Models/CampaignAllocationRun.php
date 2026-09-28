<?php

namespace App\Modules\Campaigns\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CampaignAllocationRun extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $attributes = [
        'status' => self::STATUS_SCHEDULED,
    ];

    protected $fillable = [
        'campaign_id',
        'run_key',
        'status',
        'scheduled_for',
        'started_at',
        'completed_at',
        'failed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CampaignAllocationAssignment::class);
    }
}