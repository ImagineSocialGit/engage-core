<?php

namespace App\Modules\FlowRoutes\Models;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContactFlowRoutePlan extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_FAILED = 'failed';

    public const SOURCE_TEMPLATE = 'template';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AUTOMATION = 'automation';
    public const SOURCE_VERTICAL = 'vertical';

    protected $fillable = [
        'contact_flow_route_progress_id',
        'contact_id',
        'subject_type',
        'subject_id',
        'flow_route_id',
        'status',
        'source',
        'revision',
        'flow_route_version',
        'snapshot_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'failed_at',
        'superseded_at',
        'reconciled_from_plan_id',
        'cancellation_reason',
        'failure_reason',
        'route_snapshot',
        'meta',
    ];

    protected $casts = [
        'contact_flow_route_progress_id' => 'integer',
        'contact_id' => 'integer',
        'subject_id' => 'integer',
        'flow_route_id' => 'integer',
        'revision' => 'integer',
        'flow_route_version' => 'integer',
        'snapshot_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'failed_at' => 'datetime',
        'superseded_at' => 'datetime',
        'reconciled_from_plan_id' => 'integer',
        'route_snapshot' => 'array',
        'meta' => 'array',
    ];

    public function progress(): BelongsTo
    {
        return $this->belongsTo(ContactFlowRouteProgress::class, 'contact_flow_route_progress_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function flowRoute(): BelongsTo
    {
        return $this->belongsTo(FlowRoute::class);
    }

    public function reconciledFromPlan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reconciled_from_plan_id');
    }

    public function reconciledPlan(): HasOne
    {
        return $this->hasOne(self::class, 'reconciled_from_plan_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContactFlowRoutePlanItem::class, 'contact_flow_route_plan_id')
            ->orderBy('sort_order')
            ->orderBy('sequence')
            ->orderBy('id');
    }

    public function progressItems(): HasMany
    {
        return $this->hasMany(ContactFlowRouteProgressItem::class, 'contact_flow_route_plan_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_SUPERSEDED,
            self::STATUS_FAILED,
        ]);
    }

    public function scopeForContact(Builder $query, int $contactId): Builder
    {
        return $query->where('contact_id', $contactId);
    }

    public function scopeForSubject(Builder $query, ?string $subjectType, int|string|null $subjectId): Builder
    {
        if ($subjectType === null && $subjectId === null) {
            return $query
                ->whereNull('subject_type')
                ->whereNull('subject_id');
        }

        return $query
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId);
    }
}

