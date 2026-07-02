<?php

namespace App\Modules\Forms\Models;

use Database\Factories\FormSubmissionValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormSubmissionValue extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): FormSubmissionValueFactory
    {
        return FormSubmissionValueFactory::new();
    }

    protected $fillable = [
        'form_submission_id',
        'field_key',
        'field_label',
        'field_type',
        'value',
        'value_text',
        'value_number',
        'value_boolean',
        'value_date',
        'value_datetime',
        'sort_order',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'form_submission_id' => 'integer',
            'value' => 'array',
            'value_number' => 'decimal:4',
            'value_boolean' => 'boolean',
            'value_date' => 'date',
            'value_datetime' => 'datetime',
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }

    public function formSubmission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class);
    }
}
