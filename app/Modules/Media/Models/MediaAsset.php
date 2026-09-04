<?php

namespace App\Modules\Media\Models;

use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MediaAsset extends Model
{
    use HasFactory;

    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';
    public const KIND_AUDIO = 'audio';
    public const KIND_DOCUMENT = 'document';
    public const KIND_FILE = 'file';

    public const VISIBILITY_PUBLIC = 'public';

    protected $fillable = [
        'uuid',
        'uploaded_by_type',
        'uploaded_by_id',
        'title',
        'kind',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum_sha256',
        'perceptual_hash',
        'perceptual_hash_algorithm',
        'image_width',
        'image_height',
        'visibility',
        'source',
        'meta',
        'archived_at',
    ];

    protected static function newFactory(): MediaAssetFactory
    {
        return MediaAssetFactory::new();
    }

    protected function casts(): array
    {
        return [
            'uploaded_by_id' => 'integer',
            'size_bytes' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'meta' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function uploadedBy(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'uploaded_by_type', 'uploaded_by_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function publicUrl(): ?string
    {
        if ($this->disk === null || $this->path === null) {
            return null;
        }

        try {
            $url = Storage::disk($this->disk)->url($this->path);
        } catch (Throwable) {
            return null;
        }

        return is_string($url) && trim($url) !== ''
            ? trim($url)
            : null;
    }
}