<?php

namespace App\Modules\Location\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\ContactLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactLocation extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): ContactLocationFactory
    {
        return ContactLocationFactory::new();
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const TYPE_HOME = 'home';
    public const TYPE_WORK = 'work';
    public const TYPE_SERVICE = 'service';
    public const TYPE_BILLING = 'billing';
    public const TYPE_SHIPPING = 'shipping';
    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'contact_id',
        'location_id',
        'subject_type',
        'subject_id',
        'type',
        'label',
        'status',
        'is_primary',
        'verified_at',
        'valid_from',
        'valid_until',
        'source',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'contact_id' => 'integer',
            'location_id' => 'integer',
            'subject_id' => 'integer',
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
