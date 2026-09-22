<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommerceInventoryEffect extends Model
{
    public const STATUS_RECORDED = 'recorded';
    public const STATUS_ADJUSTED = 'adjusted';
    public const STATUS_RECONCILED = 'reconciled';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'commerce_product_variant_id',
        'source_type',
        'source_key',
        'source_reference',
        'reason',
        'quantity_delta',
        'authority_mode',
        'inventory_scope',
        'status',
        'idempotency_key',
        'payload_fingerprint',
        'occurred_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'commerce_product_variant_id' => 'integer',
            'quantity_delta' => 'decimal:4',
            'authority_mode' => CommerceInventoryAuthorityMode::class,
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function commerceProductVariant(): BelongsTo
    {
        return $this->belongsTo(CommerceProductVariant::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(CommerceInventoryAdjustment::class);
    }

    public function requiresAuthorityAdjustment(): bool
    {
        return $this->authority_mode === CommerceInventoryAuthorityMode::AdjustmentRequired;
    }

    public function authorityAlreadyApplied(): bool
    {
        return $this->authority_mode === CommerceInventoryAuthorityMode::AuthorityAlreadyApplied;
    }
}