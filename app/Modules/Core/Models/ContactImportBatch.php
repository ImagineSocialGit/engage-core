<?php

namespace App\Modules\Core\Models;

use Database\Factories\ContactImportBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactImportBatch extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected static function newFactory(): ContactImportBatchFactory
    {
        return ContactImportBatchFactory::new();
    }

    protected $fillable = [
        'name',
        'source',
        'original_filename',
        'status',
        'imported_at',
        'contact_count',
        'successful_count',
        'failed_count',
        'meta',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
        'contact_count' => 'integer',
        'successful_count' => 'integer',
        'failed_count' => 'integer',
        'meta' => 'array',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'contact_import_batch_id');
    }
}
