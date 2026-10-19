<?php

namespace App\Modules\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class MediaAssetAssignment extends Model
{
    protected $fillable = [
        'media_asset_id',
        'subject_type',
        'subject_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'media_asset_id' => 'integer',
            'subject_id' => 'integer',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}