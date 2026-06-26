<?php

namespace App\Modules\FlowRoutes\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class ContactFlowRouteProgress extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_WAITING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_SUPERSEDED,
        self::STATUS_FAILED,
    ];

    protected $table = 'contact_flow_route_progress';

    protected $fillable = [
        'contact_id',
        'contact_status_id',
        'contact_workflow_profile_id',
        'flow_route_id',
        'current_flow_route_point_id',
        'status',
        'started_at',
        'completed_at',
        'cancelled_at',
        'failed_at',
        'cancellation_reason',
        'failure_reason',
        'meta',
    ];

    protected $casts = [
        'contact_id' => 'integer',
        'contact_status_id' => 'integer',
        'contact_workflow_profile_id' => 'integer',
        'flow_route_id' => 'integer',
        'current_flow_route_point_id' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'failed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function contactStatus(): BelongsTo
    {
        return $this->belongsTo(ContactStatus::class);
    }

    public function contactWorkflowProfile(): BelongsTo
    {
        return $this->belongsTo(ContactWorkflowProfile::class);
    }

    public function flowRoute(): BelongsTo
    {
        return $this->belongsTo(FlowRoute::class);
    }

    public function currentFlowRoutePoint(): BelongsTo
    {
        return $this->belongsTo(FlowRoutePoint::class, 'current_flow_route_point_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITING);
    }

    public function scopeRunnable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_ACTIVE,
            self::STATUS_WAITING,
        ]);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUPERSEDED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
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

    public function scopeForWorkflowProfile(Builder $query, int $contactWorkflowProfileId): Builder
    {
        return $query->where('contact_workflow_profile_id', $contactWorkflowProfileId);
    }

    public function scopeForContactStatus(Builder $query, int $contactStatusId): Builder
    {
        return $query->where('contact_status_id', $contactStatusId);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    public function isRunnable(): bool
    {
        return in_array($this->status, [
            self::STATUS_ACTIVE,
            self::STATUS_WAITING,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_SUPERSEDED,
            self::STATUS_FAILED,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function waitingState(): array
    {
        $waiting = ($this->meta ?? [])['waiting'] ?? [];

        return is_array($waiting) ? $waiting : [];
    }

    public function waitingFlowRoutePointId(): ?int
    {
        $flowRoutePointId = $this->waitingState()['flow_route_point_id'] ?? null;

        return is_numeric($flowRoutePointId) ? (int) $flowRoutePointId : null;
    }

    public function waitingResumeAt(): ?CarbonImmutable
    {
        $resumeAt = $this->waitingState()['resume_at'] ?? null;

        if (! is_string($resumeAt) || trim($resumeAt) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($resumeAt)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public function isDueToResume(?CarbonInterface $now = null): bool
    {
        $resumeAt = $this->waitingResumeAt();

        if (! $resumeAt instanceof CarbonImmutable) {
            return false;
        }

        $now = $now
            ? CarbonImmutable::instance($now)->utc()
            : CarbonImmutable::now('UTC');

        return $resumeAt->lessThanOrEqualTo($now);
    }
}