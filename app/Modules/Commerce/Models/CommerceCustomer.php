<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\CommerceCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommerceCustomer extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): CommerceCustomerFactory
    {
        return CommerceCustomerFactory::new();
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'contact_id',
        'first_name',
        'last_name',
        'name',
        'email',
        'phone',
        'status',
        'currency',
        'first_ordered_at',
        'last_ordered_at',
        'total_orders',
        'total_spent_cents',
        'source',
        'provider',
        'external_id',
        'external_url',
        'raw_payload',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'contact_id' => 'integer',
            'first_ordered_at' => 'datetime',
            'last_ordered_at' => 'datetime',
            'total_orders' => 'integer',
            'total_spent_cents' => 'integer',
            'raw_payload' => 'array',
            'meta' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(CommerceOrder::class);
    }
}
