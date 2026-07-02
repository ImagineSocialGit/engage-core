<?php

namespace App\Modules\Documents\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\DocumentRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): DocumentRequestFactory
    {
        return DocumentRequestFactory::new();
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_VIEWED = 'viewed';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_REPLACEMENT_REQUESTED = 'replacement_requested';
    public const STATUS_SATISFIED = 'satisfied';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'document_requirement_definition_id',
        'contact_id',
        'subject_type',
        'subject_id',
        'requested_by_type',
        'requested_by_id',
        'assigned_to_type',
        'assigned_to_id',
        'title',
        'instructions',
        'status',
        'priority',
        'request_token',
        'requested_at',
        'sent_at',
        'opened_at',
        'first_uploaded_at',
        'last_uploaded_at',
        'satisfied_at',
        'waived_at',
        'expired_at',
        'cancelled_at',
        'expires_at',
        'source',
        'provider',
        'external_id',
        'external_url',
        'settings',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'document_requirement_definition_id' => 'integer',
            'contact_id' => 'integer',
            'subject_id' => 'integer',
            'requested_by_id' => 'integer',
            'assigned_to_id' => 'integer',
            'requested_at' => 'datetime',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'first_uploaded_at' => 'datetime',
            'last_uploaded_at' => 'datetime',
            'satisfied_at' => 'datetime',
            'waived_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expires_at' => 'datetime',
            'settings' => 'array',
            'meta' => 'array',
        ];
    }

    public function requirementDefinition(): BelongsTo
    {
        return $this->belongsTo(DocumentRequirementDefinition::class, 'document_requirement_definition_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'requested_by_type', 'requested_by_id');
    }

    public function assignedTo(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'assigned_to_type', 'assigned_to_id');
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(DocumentUpload::class);
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(DocumentReviewEvent::class);
    }
}
