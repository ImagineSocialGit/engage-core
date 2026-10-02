<?php

namespace App\Modules\Portal\Models;

use Database\Factories\PortalUserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PortalUser extends Authenticatable implements MustVerifyEmailContract
{
    use HasFactory;
    use MustVerifyEmail;
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

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (! is_string($user->uuid) || trim($user->uuid) === '') {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'disabled_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public static function canonicalEmail(string $email): string
    {
        $email = Str::lower(trim($email));

        if ($email === ''
            || mb_strlen($email) > 255
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException('Portal account email is invalid.');
        }

        return $email;
    }

    public function setEmailAttribute(?string $email): void
    {
        if ($email === null || trim($email) === '') {
            $this->attributes['email'] = null;

            return;
        }

        $this->attributes['email'] = self::canonicalEmail($email);
    }

    public function isAuthenticatable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && is_string($this->email)
            && trim($this->email) !== ''
            && is_string($this->password)
            && trim($this->password) !== '';
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