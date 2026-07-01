<?php

namespace App\Modules\Broadcasts\Models;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Database\Factories\BroadcastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Broadcast extends Model
{
    use HasFactory;

    protected static function newFactory(): BroadcastFactory
    {
        return BroadcastFactory::new();
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const BROADCAST_TYPE_REGULAR = 'regular';
    public const BROADCAST_TYPE_PERMISSION_INVITATION = 'permission_invitation';

    public const DEFAULT_DISPATCH_KEY = 'broadcast_send';
    public const DEFAULT_MESSAGE_TYPE = 'broadcast';

    public const PERMISSION_INVITATION_DISPATCH_KEY = 'imported_contact_permission_invitation';

    public const MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION =
        ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION;

    protected $fillable = [
        'user_id',
        'name',
        'channel',
        'purpose',
        'scope',
        'dispatch_key',
        'message_type',
        'payload_class',
        'queue',
        'status',
        'send_at',
        'payload',
        'recipient_filter',
        'recipient_count',
        'scheduled_count',
        'cancelled_at',
        'completed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'send_at' => 'datetime',
            'payload' => 'array',
            'recipient_filter' => 'array',
            'recipient_count' => 'integer',
            'scheduled_count' => 'integer',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function scheduledMessages(): MorphMany
    {
        return $this->morphMany(ScheduledMessage::class, 'context');
    }

    public function isPermissionInvitation(): bool
    {
        return $this->message_type === self::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION;
    }

    public function isRegularBroadcast(): bool
    {
        return ! $this->isPermissionInvitation();
    }

    public function broadcastType(): string
    {
        return $this->isPermissionInvitation()
            ? self::BROADCAST_TYPE_PERMISSION_INVITATION
            : self::BROADCAST_TYPE_REGULAR;
    }

    public function typeLabel(): string
    {
        return $this->isPermissionInvitation()
            ? 'Opt-in invitation'
            : 'Broadcast';
    }

    public function typeDescription(): string
    {
        return $this->isPermissionInvitation()
            ? 'Email-only one-time imported-contact opt-in invitation.'
            : 'Regular consent-gated one-time broadcast.';
    }

    public function recipientFilterLabel(): string
    {
        $recipientFilter = $this->recipient_filter ?? [];

        return match ($recipientFilter['type'] ?? 'all') {
            'imported' => 'Imported contacts',
            'tag' => 'Contacts tagged '.implode(', ', $recipientFilter['tags'] ?? []),
            'contact_ids' => 'Selected contacts',
            default => 'All contacts',
        };
    }
}