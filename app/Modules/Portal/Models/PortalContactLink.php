<?php

namespace App\Modules\Portal\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\PortalContactLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PortalContactLink extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): PortalContactLinkFactory
    {
        return PortalContactLinkFactory::new();
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    public const RELATIONSHIP_SELF = 'self';
    public const RELATIONSHIP_PARENT = 'parent';
    public const RELATIONSHIP_SPOUSE = 'spouse';
    public const RELATIONSHIP_COMPANY_CONTACT = 'company_contact';
    public const RELATIONSHIP_GUARDIAN = 'guardian';

    protected $fillable = [
        'portal_user_id',
        'contact_id',
        'relationship',
        'status',
        'is_primary',
        'linked_at',
        'verified_at',
        'revoked_at',
        'source',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'portal_user_id' => 'integer',
            'contact_id' => 'integer',
            'is_primary' => 'boolean',
            'linked_at' => 'datetime',
            'verified_at' => 'datetime',
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
