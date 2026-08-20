<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplateCompositionLayer extends Model
{
    public const SCOPE_PLATFORM = 'platform';
    public const SCOPE_CLIENT = 'client';
    public const SCOPE_FAMILY = 'family';
    public const SCOPE_CONTEXT = 'context';
    public const SCOPE_CONTEXT_FAMILY = 'context_family';
    public const SCOPE_MESSAGE = 'message';

    protected $fillable = [
        'identity_key',
        'scope_type',
        'channel',
        'client_key',
        'context_key',
        'family_key',
        'message_template_id',
        'payload',
        'source',
        'source_version',
        'is_customized',
        'customized_at',
    ];

    protected function casts(): array
    {
        return [
            'message_template_id' => 'integer',
            'payload' => 'array',
            'is_customized' => 'boolean',
            'customized_at' => 'datetime',
        ];
    }

    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }
}