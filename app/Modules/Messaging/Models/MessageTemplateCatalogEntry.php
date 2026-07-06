<?php

namespace App\Modules\Messaging\Models;

use Database\Factories\MessageTemplateCatalogEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MessageTemplateCatalogEntry extends Model
{
    use HasFactory;

    protected static function newFactory(): MessageTemplateCatalogEntryFactory
    {
        return MessageTemplateCatalogEntryFactory::new();
    }

    protected $fillable = [
        'message_template_preset_id',
        'channel',
        'purpose',
        'scope',
        'module_key',
        'module_label',
        'surface',
        'group_key',
        'group_label',
        'item_key',
        'item_label',
        'item_order',
        'usage_type',
        'source',
        'source_config_path',
        'context_type',
        'context_id',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'message_template_preset_id' => 'integer',
            'item_order' => 'integer',
            'context_id' => 'integer',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function messageTemplatePreset(): BelongsTo
    {
        return $this->belongsTo(MessageTemplatePreset::class);
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
