<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactImportRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_FINALIZING = 'finalizing';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'contact_import_batch_id',
        'status',
        'csv_path',
        'import_mode',
        'headers',
        'mapping',
        'profile_key',
        'profile_defaults',
        'treatment_selections',
        'post_import_config',
        'processing_stats',
        'actor_user_id',
        'total_rows',
        'processed_rows',
        'next_row_number',
        'next_byte_offset',
        'queued_at',
        'started_at',
        'finalizing_at',
        'failed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'contact_import_batch_id' => 'integer',
            'headers' => 'array',
            'mapping' => 'array',
            'profile_defaults' => 'array',
            'treatment_selections' => 'array',
            'post_import_config' => 'array',
            'processing_stats' => 'array',
            'actor_user_id' => 'integer',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'next_row_number' => 'integer',
            'next_byte_offset' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finalizing_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ContactImportBatch::class, 'contact_import_batch_id');
    }

    public function isRunnable(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ], true);
    }

    public function isFinalizing(): bool
    {
        return $this->status === self::STATUS_FINALIZING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}