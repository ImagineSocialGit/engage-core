<?php

namespace App\Modules\Messaging\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageTemplateVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'message_template_id',
        'version',
        'subject',
        'content',
        'renderer_key',
        'renderer_version',
        'content_hash',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'message_template_id' => 'integer',
            'version' => 'integer',
            'content' => 'array',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function chainStepVariants(): HasMany
    {
        return $this->hasMany(MessageChainStepVariant::class);
    }

    public function scheduledMessageComponents(): HasMany
    {
        return $this->hasMany(ScheduledMessageComponent::class);
    }
}