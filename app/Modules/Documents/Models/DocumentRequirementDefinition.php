<?php

namespace App\Modules\Documents\Models;

use Database\Factories\DocumentRequirementDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentRequirementDefinition extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): DocumentRequirementDefinitionFactory
    {
        return DocumentRequirementDefinitionFactory::new();
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_IDENTITY = 'identity';
    public const CATEGORY_AGREEMENT = 'agreement';
    public const CATEGORY_FINANCIAL = 'financial';
    public const CATEGORY_HEALTH = 'health';
    public const CATEGORY_ASSET = 'asset';

    protected $fillable = [
        'key',
        'name',
        'description',
        'instructions',
        'status',
        'category',
        'is_required_by_default',
        'allows_multiple_uploads',
        'requires_review',
        'accepted_mime_types',
        'max_file_size_kb',
        'expires_after_days',
        'sort_order',
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
            'is_required_by_default' => 'boolean',
            'allows_multiple_uploads' => 'boolean',
            'requires_review' => 'boolean',
            'accepted_mime_types' => 'array',
            'max_file_size_kb' => 'integer',
            'expires_after_days' => 'integer',
            'sort_order' => 'integer',
            'settings' => 'array',
            'meta' => 'array',
        ];
    }

    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    public function documentUploads(): HasMany
    {
        return $this->hasMany(DocumentUpload::class);
    }
}
