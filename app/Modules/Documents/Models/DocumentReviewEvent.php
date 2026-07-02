<?php

namespace App\Modules\Documents\Models;

use Database\Factories\DocumentReviewEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentReviewEvent extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): DocumentReviewEventFactory
    {
        return DocumentReviewEventFactory::new();
    }

    public const EVENT_REQUESTED = 'requested';
    public const EVENT_SENT = 'sent';
    public const EVENT_OPENED = 'opened';
    public const EVENT_UPLOADED = 'uploaded';
    public const EVENT_REVIEWED = 'reviewed';
    public const EVENT_APPROVED = 'approved';
    public const EVENT_REJECTED = 'rejected';
    public const EVENT_REPLACEMENT_REQUESTED = 'replacement_requested';
    public const EVENT_WAIVED = 'waived';
    public const EVENT_EXPIRED = 'expired';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_ARCHIVED = 'archived';

    protected $fillable = [
        'document_request_id',
        'document_upload_id',
        'actor_type',
        'actor_id',
        'event',
        'from_status',
        'to_status',
        'reason',
        'notes',
        'occurred_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'document_request_id' => 'integer',
            'document_upload_id' => 'integer',
            'actor_id' => 'integer',
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function documentUpload(): BelongsTo
    {
        return $this->belongsTo(DocumentUpload::class);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
