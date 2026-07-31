<?php

namespace App\Modules\Messaging\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MessageTemplateVersion extends Model
{
    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('MessageTemplateVersion records are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('MessageTemplateVersion records are immutable.');
        });
    }

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

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $content = is_array($this->content) ? $this->content : [];

        if (is_string($this->subject) && $this->subject !== '') {
            return ['subject' => $this->subject] + $content;
        }

        return $content;
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