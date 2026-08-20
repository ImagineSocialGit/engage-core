<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledMessageCtaEngagement extends Model
{
    public const CLASSIFICATION_LIKELY_HUMAN = 'likely_human';
    public const CLASSIFICATION_SCANNER = 'scanner';
    public const CLASSIFICATION_PREFETCH = 'prefetch';
    public const CLASSIFICATION_UNKNOWN = 'unknown';

    public $timestamps = false;

    protected $fillable = [
        'scheduled_message_id',
        'cta_key',
        'classification',
        'occurrence_count',
        'first_occurred_at',
        'last_occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_message_id' => 'integer',
            'occurrence_count' => 'integer',
            'first_occurred_at' => 'datetime',
            'last_occurred_at' => 'datetime',
        ];
    }

    public static function classifications(): array
    {
        return [
            self::CLASSIFICATION_LIKELY_HUMAN,
            self::CLASSIFICATION_SCANNER,
            self::CLASSIFICATION_PREFETCH,
            self::CLASSIFICATION_UNKNOWN,
        ];
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }
}