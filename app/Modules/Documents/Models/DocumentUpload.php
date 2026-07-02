<?php

namespace App\Modules\Documents\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\DocumentUploadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentUpload extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): DocumentUploadFactory
    {
        return DocumentUploadFactory::new();
    }

    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_DELETED = 'deleted';

    public const REVIEW_STATUS_PENDING = 'pending';
    public const REVIEW_STATUS_APPROVED = 'approved';
    public const REVIEW_STATUS_REJECTED = 'rejected';
    public const REVIEW_STATUS_NEEDS_REPLACEMENT = 'needs_replacement';

    public const STORAGE_VISIBILITY_PRIVATE = 'private';
    public const STORAGE_VISIBILITY_PUBLIC = 'public';

    protected $fillable = [
        'document_request_id',
        'document_requirement_definition_id',
        'contact_id',
        'subject_type',
        'subject_id',
        'uploaded_by_type',
        'uploaded_by_id',
        'replaces_document_upload_id',
        'title',
        'status',
        'review_status',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum',
        'storage_visibility',
        'submitted_at',
        'reviewed_at',
        'approved_at',
        'rejected_at',
        'expires_at',
        'source',
        'provider',
        'external_id',
        'external_url',
        'metadata',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'document_request_id' => 'integer',
            'document_requirement_definition_id' => 'integer',
            'contact_id' => 'integer',
            'subject_id' => 'integer',
            'uploaded_by_id' => 'integer',
            'replaces_document_upload_id' => 'integer',
            'size_bytes' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
            'meta' => 'array',
        ];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
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

    public function uploadedBy(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'uploaded_by_type', 'uploaded_by_id');
    }

    public function replacesDocumentUpload(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_upload_id');
    }

    public function replacementUploads(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_document_upload_id');
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(DocumentReviewEvent::class);
    }
}
