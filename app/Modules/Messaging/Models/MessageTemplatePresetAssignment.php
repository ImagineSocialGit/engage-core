<?php

namespace App\Modules\Messaging\Models;

use Database\Factories\MessageTemplatePresetAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MessageTemplatePresetAssignment extends Model
{
    use HasFactory;

    protected static function newFactory(): MessageTemplatePresetAssignmentFactory
    {
        return MessageTemplatePresetAssignmentFactory::new();
    }

    protected $fillable = [
        'message_template_preset_id',
        'channel',
        'purpose',
        'scope',
        'surface',
        'message_type',
        'definition_key',
        'campaign_key',
        'campaign_step',
        'campaign_step_variant_key',
        'source_config_path',
        'context_type',
        'context_id',
        'is_active',
        'starts_at',
        'ends_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'message_template_preset_id' => 'integer',
            'campaign_step' => 'integer',
            'context_id' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
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
        return $query
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            });
    }
}
