<?php

namespace App\Modules\Portal\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\PortalInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PortalInvitation extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): PortalInvitationFactory
    {
        return PortalInvitationFactory::new();
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    public const PURPOSE_ACCOUNT_ACCESS = 'account_access';
    public const PURPOSE_SELF_BOOKING = 'self_booking';
    public const PURPOSE_DOCUMENT_UPLOAD = 'document_upload';

    protected $fillable = [
        'portal_user_id',
        'contact_id',
        'email',
        'phone',
        'token_hash',
        'status',
        'channel',
        'purpose',
        'expires_at',
        'sent_at',
        'accepted_at',
        'revoked_at',
        'accepted_ip',
        'accepted_user_agent',
        'source',
        'provider',
        'external_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'portal_user_id' => 'integer',
            'contact_id' => 'integer',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
