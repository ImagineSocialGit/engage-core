<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactImportOccurrence extends Model
{
    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_UPDATED = 'updated';

    protected $fillable = [
        'contact_import_batch_id',
        'contact_id',
        'row_number',
        'outcome',
        'identity_type',
        'identity_value',
        'original_source',
        'original_subsource',
        'original_status',
        'row_fingerprint',
        'meta',
    ];

    protected $casts = [
        'row_number' => 'integer',
        'meta' => 'array',
    ];

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ContactImportBatch::class, 'contact_import_batch_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}