<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Messaging\Models\MessageChain;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    protected static function newFactory(): CampaignFactory
    {
        return CampaignFactory::new();
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const ENROLLMENT_MODE_MANUAL = 'manual';
    public const ENROLLMENT_MODE_AUTOMATIC = 'automatic';
    public const ENROLLMENT_MODES = [
        self::ENROLLMENT_MODE_MANUAL,
        self::ENROLLMENT_MODE_AUTOMATIC,
    ];

    public const REENTRY_NEVER = 'never';
    public const REENTRY_WHEN_ELIGIBLE_AGAIN = 'when_eligible_again';
    public const REENTRY_POLICIES = [
        self::REENTRY_NEVER,
        self::REENTRY_WHEN_ELIGIBLE_AGAIN,
    ];

    public const INELIGIBLE_CONTINUE = 'continue';
    public const INELIGIBLE_PAUSE = 'pause';
    public const INELIGIBLE_CANCEL = 'cancel';
    public const INELIGIBLE_BEHAVIORS = [
        self::INELIGIBLE_CONTINUE,
        self::INELIGIBLE_PAUSE,
        self::INELIGIBLE_CANCEL,
    ];

    protected $fillable = [
        'key',
        'name',
        'description',
        'message_chain_id',
        'family_key',
        'priority',
        'eligibility_filter',
        'enrollment_mode',
        'reentry_policy',
        'ineligible_behavior',
        'channel',
        'purpose',
        'scope',
        'status',
        'source_version',
        'is_customized',
        'customized_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'message_chain_id' => 'integer',
            'priority' => 'integer',
            'eligibility_filter' => 'array',
            'is_customized' => 'boolean',
            'customized_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function messageChain(): BelongsTo
    {
        return $this->belongsTo(MessageChain::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class)->orderBy('step_number');
    }

    public function activeSteps(): HasMany
    {
        return $this->steps()->where('is_active', true);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CampaignEnrollment::class);
    }

    public function eligibilityStates(): HasMany
    {
        return $this->hasMany(CampaignEligibilityState::class);
    }

    public function hasEligibilityCriteria(): bool
    {
        return is_array($this->eligibility_filter)
            && $this->eligibility_filter !== [];
    }

    public function usesAutomaticEnrollment(): bool
    {
        return $this->enrollment_mode === self::ENROLLMENT_MODE_AUTOMATIC;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    public function scopeCustomized(Builder $query): Builder
    {
        return $query->where('is_customized', true);
    }

    public function scopeNotCustomized(Builder $query): Builder
    {
        return $query->where('is_customized', false);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}