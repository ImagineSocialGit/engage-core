<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ScheduledMessageBulkEdit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'source_scope', 'source_id', 'message_template_version_id',
        'maximum_scheduled_message_id', 'channel', 'matching_count_at_creation',
        'override_payload', 'action', 'actor_id', 'actor_email', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'message_template_version_id' => 'integer',
            'maximum_scheduled_message_id' => 'integer',
            'matching_count_at_creation' => 'integer',
            'override_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Bulk message edits are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Bulk message edits are immutable.');
        });
    }
}