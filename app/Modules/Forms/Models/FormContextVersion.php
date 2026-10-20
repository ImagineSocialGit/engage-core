<?php

namespace App\Modules\Forms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FormContextVersion extends Model
{
    protected $fillable = [
        'form_definition_id',
        'context_key',
        'context_reference',
        'form_version_id',
    ];

    protected function casts(): array
    {
        return [
            'form_definition_id' => 'integer',
            'form_version_id' => 'integer',
        ];
    }

    public function formDefinition(): BelongsTo
    {
        return $this->belongsTo(FormDefinition::class);
    }

    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }
}