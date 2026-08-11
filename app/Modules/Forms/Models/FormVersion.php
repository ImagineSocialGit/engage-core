<?php

namespace App\Modules\Forms\Models;

use Database\Factories\FormVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

class FormVersion extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): FormVersionFactory
    {
        return FormVersionFactory::new();
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected static function booted(): void
    {
        static::updating(function (FormVersion $version): void {
            $wasPublished = $version->getOriginal('status') === self::STATUS_PUBLISHED
                || $version->getOriginal('published_at') !== null;

            if (! $wasPublished) {
                return;
            }

            throw new LogicException(sprintf(
                'Published FormVersion [%s] is immutable. Publish a new version instead of editing the existing snapshot.',
                $version->getKey(),
            ));
        });

        static::deleting(function (FormVersion $version): void {
            $isPublished = $version->status === self::STATUS_PUBLISHED
                || $version->published_at !== null;

            if (! $isPublished) {
                return;
            }

            throw new LogicException(sprintf(
                'Published FormVersion [%s] cannot be deleted because submissions must remain traceable to their published schema snapshot.',
                $version->getKey(),
            ));
        });
    }

    protected $fillable = [
        'form_definition_id',
        'version',
        'status',
        'name',
        'description',
        'schema',
        'rules',
        'layout',
        'settings',
        'published_at',
        'archived_at',
        'source',
        'provider',
        'external_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'form_definition_id' => 'integer',
            'version' => 'integer',
            'schema' => 'array',
            'rules' => 'array',
            'layout' => 'array',
            'settings' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function formDefinition(): BelongsTo
    {
        return $this->belongsTo(FormDefinition::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}