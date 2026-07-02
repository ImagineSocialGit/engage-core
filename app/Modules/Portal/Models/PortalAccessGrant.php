<?php

namespace App\Modules\Portal\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\PortalAccessGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PortalAccessGrant extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): PortalAccessGrantFactory
    {
        return PortalAccessGrantFactory::new();
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    public const CAPABILITY_VIEW = 'view';
    public const CAPABILITY_SUBMIT = 'submit';
    public const CAPABILITY_UPLOAD = 'upload';
    public const CAPABILITY_BOOK = 'book';
    public const CAPABILITY_PAY = 'pay';
    public const CAPABILITY_MANAGE = 'manage';

    protected $fillable = [
        'portal_user_id',
        'contact_id',
        'grantable_type',
        'grantable_id',
        'capability',
        'status',
        'starts_at',
        'expires_at',
        'granted_by_type',
        'granted_by_id',
        'revoked_at',
        'source',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'portal_user_id' => 'integer',
            'contact_id' => 'integer',
            'grantable_id' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'granted_by_id' => 'integer',
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

    public function grantable(): MorphTo
    {
        return $this->morphTo();
    }

    public function grantedBy(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'granted_by_type', 'granted_by_id');
    }
}
