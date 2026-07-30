<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageChainStepVariant extends Model
{
    protected $fillable = [
        'message_chain_step_id',
        'key',
        'sort_order',
        'message_template_version_id',
        'channel',
        'purpose',
        'scope',
        'message_type',
        'queue',
        'dependency_policy',
        'conditions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'message_chain_step_id' => 'integer',
            'sort_order' => 'integer',
            'message_template_version_id' => 'integer',
            'dependency_policy' => 'array',
            'conditions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function messageChainStep(): BelongsTo
    {
        return $this->belongsTo(MessageChainStep::class);
    }

    public function messageTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(MessageTemplateVersion::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}