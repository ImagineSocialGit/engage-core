<?php

namespace App\Modules\Scheduling\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SchedulingBookingOffer extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $attributes = [
        'status' => self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'bookable_service_id',
        'code',
        'name',
        'status',
        'starts_at',
        'ends_at',
        'claim_limit',
        'ineligible_message',
        'exhausted_message',
        'meta',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $offer): void {
            $offer->code = self::normalizeCode((string) $offer->code);
            $offer->assertValidDefinition();
        });
    }

    protected function casts(): array
    {
        return [
            'bookable_service_id' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'claim_limit' => 'integer',
            'meta' => 'array',
        ];
    }

    public function bookableService(): BelongsTo
    {
        return $this->belongsTo(BookableService::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(SchedulingBookingOfferCondition::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(SchedulingBookingOfferReward::class)
            ->orderBy('max_claim_number')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(SchedulingBookingOfferClaim::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isOpenAt(?CarbonInterface $at = null): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $at = $at !== null
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        if ($this->starts_at !== null
            && CarbonImmutable::instance($this->starts_at)->utc()->greaterThan($at)
        ) {
            return false;
        }

        if ($this->ends_at !== null
            && CarbonImmutable::instance($this->ends_at)->utc()->lessThanOrEqualTo($at)
        ) {
            return false;
        }

        return true;
    }

    public static function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    private function assertValidDefinition(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException(
                "Unsupported Scheduling booking offer status [{$this->status}].",
            );
        }

        if (preg_match('/\A[A-Z0-9][A-Z0-9_-]{2,39}\z/', $this->code) !== 1) {
            throw new InvalidArgumentException(
                'Scheduling booking offer codes must be 3-40 characters using letters, numbers, dashes, or underscores.',
            );
        }

        if (! is_string($this->name) || trim($this->name) === '') {
            throw new InvalidArgumentException(
                'Scheduling booking offers require a name.',
            );
        }

        if ($this->claim_limit !== null && (int) $this->claim_limit < 1) {
            throw new InvalidArgumentException(
                'Scheduling booking offer claim limits must be at least 1 when configured.',
            );
        }

        if ($this->starts_at !== null
            && $this->ends_at !== null
            && CarbonImmutable::instance($this->starts_at)->utc()
                ->greaterThanOrEqualTo(CarbonImmutable::instance($this->ends_at)->utc())
        ) {
            throw new InvalidArgumentException(
                'Scheduling booking offer start time must be before its end time.',
            );
        }
    }
}