<?php

namespace App\Modules\Portal\Models;

use Database\Factories\PortalUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class PortalUser extends Model
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected static function newFactory(): PortalUserFactory
    {
        return PortalUserFactory::new();
    }

    public const STATUS_INVITED = 'invited';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'phone',
        'password',
        'status',
        'email_verified_at',
        'phone_verified_at',
        'last_login_at',
        'invited_at',
        'accepted_at',
        'disabled_at',
        'source',
        'provider',
        'external_id',
        'meta',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'disabled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function contactLinks(): HasMany
    {
        return $this->hasMany(PortalContactLink::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(PortalInvitation::class);
    }

    public function accessGrants(): HasMany
    {
        return $this->hasMany(PortalAccessGrant::class);
    }
}
