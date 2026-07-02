<?php

namespace App\Modules\Forms\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\FormSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormSubmission extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): FormSubmissionFactory
    {
        return FormSubmissionFactory::new();
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CANCELED = 'canceled';

    public const REVIEW_STATUS_PENDING = 'pending';
    public const REVIEW_STATUS_APPROVED = 'approved';
    public const REVIEW_STATUS_REJECTED = 'rejected';
    public const REVIEW_STATUS_NEEDS_CHANGES = 'needs_changes';

    protected $fillable = [
        'form_definition_id',
        'form_version_id',
        'contact_id',
        'subject_type',
        'subject_id',
        'status',
        'review_status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by_type',
        'reviewed_by_id',
        'source',
        'provider',
        'external_id',
        'ip_address',
        'user_agent',
        'payload',
        'raw_payload',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'form_definition_id' => 'integer',
            'form_version_id' => 'integer',
            'contact_id' => 'integer',
            'subject_id' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'reviewed_by_id' => 'integer',
            'payload' => 'array',
            'raw_payload' => 'array',
            'meta' => 'array',
        ];
    }

    public function formDefinition(): BelongsTo
    {
        return $this->belongsTo(FormDefinition::class);
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewedBy(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reviewed_by_type', 'reviewed_by_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(FormSubmissionValue::class);
    }
}
