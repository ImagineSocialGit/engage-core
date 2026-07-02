<?php

namespace App\Modules\Forms\Models;

use Database\Factories\FormDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormDefinition extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): FormDefinitionFactory
    {
        return FormDefinitionFactory::new();
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const CATEGORY_INTAKE = 'intake';
    public const CATEGORY_QUESTIONNAIRE = 'questionnaire';
    public const CATEGORY_REVIEW = 'review';
    public const CATEGORY_REQUEST = 'request';
    public const CATEGORY_FEEDBACK = 'feedback';

    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
        'category',
        'is_public',
        'current_form_version_id',
        'source',
        'provider',
        'external_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'current_form_version_id' => 'integer',
            'meta' => 'array',
        ];
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'current_form_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}
