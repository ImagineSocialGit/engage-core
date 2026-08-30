<?php

namespace App\Modules\Broadcasts\Models;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use Database\Factories\BroadcastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'message_template_id',
        'message_template_version_id',
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
            'message_template_id' => 'integer',
            'message_template_version_id' => 'integer',
            'send_at' => 'datetime',
            'recipient_filter' => 'array',
            'recipient_count' => 'integer',
            'scheduled_count' => 'integer',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    public function messageTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(MessageTemplateVersion::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function scheduledMessages(): MorphMany
    {
        return $this->morphMany(ScheduledMessage::class, 'context');
    }

    /**
     * Return the authored Broadcast copy from its private Messaging template.
     * Drafts read the current private version. Once scheduled, the exact pinned
     * version remains authoritative even if the template's current version were
     * ever changed later.
     *
     * @return array<string, mixed>
     */
    public function messagePayload(): array
    {
        if (is_numeric($this->message_template_version_id)) {
            $version = $this->relationLoaded('messageTemplateVersion')
                ? $this->getRelation('messageTemplateVersion')
                : $this->messageTemplateVersion()->first();

            if ($version instanceof MessageTemplateVersion) {
                return $version->payload();
            }
        }

        $template = $this->relationLoaded('messageTemplate')
            ? $this->getRelation('messageTemplate')
            : $this->messageTemplate()->first();

        return $template instanceof MessageTemplate
            ? $template->currentPayload()
            : [];
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
            'import_batch' => 'Imported contacts from selected batches',
            'tag' => 'Contacts tagged '.implode(', ', $recipientFilter['tags'] ?? []),
            'contact_ids' => 'Selected contacts',
            default => 'All contacts',
        };
    }
}