<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardAcknowledgement extends Model
{
    public const SURFACE_CRM_DASHBOARD = 'crm_dashboard';

    public const TYPE_TASK = 'task';
    public const TYPE_INBOUND_MESSAGE = 'inbound_message';
    public const TYPE_WEBINAR_REGISTRATION = 'webinar_registration';
    public const TYPE_WEBINAR_WAITLIST_SIGNUP = 'webinar_waitlist_signup';

    protected $fillable = [
        'user_id',
        'surface',
        'item_type',
        'item_key',
        'acknowledged_at',
        'expires_at',
        'meta',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'acknowledged_at' => 'datetime',
        'expires_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeSurface(Builder $query, string $surface): Builder
    {
        return $query->where('surface', self::normalizeSurface($surface));
    }

    public function scopeItem(Builder $query, string $itemType, string|int $itemKey): Builder
    {
        return $query
            ->where('item_type', self::normalizeItemType($itemType))
            ->where('item_key', self::normalizeItemKey($itemKey));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public static function normalizeSurface(string $surface): string
    {
        return str_replace('-', '_', strtolower(trim($surface)));
    }

    public static function normalizeItemType(string $itemType): string
    {
        return str_replace('-', '_', strtolower(trim($itemType)));
    }

    public static function normalizeItemKey(string|int $itemKey): string
    {
        return trim((string) $itemKey);
    }
}
