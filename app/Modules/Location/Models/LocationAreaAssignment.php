<?php

namespace App\Modules\Location\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\LocationAreaAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LocationAreaAssignment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): LocationAreaAssignmentFactory
    {
        return LocationAreaAssignmentFactory::new();
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_EXPIRED = 'expired';

    public const ROLE_MEMBER = 'member';
    public const ROLE_SERVICEABLE = 'serviceable';
    public const ROLE_EXCLUDED = 'excluded';

    protected $fillable = [
        'location_area_id',
        'location_id',
        'contact_id',
        'subject_type',
        'subject_id',
        'role',
        'status',
        'starts_at',
        'expires_at',
        'source',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'location_area_id' => 'integer',
            'location_id' => 'integer',
            'contact_id' => 'integer',
            'subject_id' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function locationArea(): BelongsTo
    {
        return $this->belongsTo(LocationArea::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
