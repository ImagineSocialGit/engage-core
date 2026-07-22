<?php

namespace App\Modules\Webinars\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarRegistrationResponse extends Model
{
    protected $fillable = [
        'webinar_registration_id',
        'question_key',
        'question_label',
        'question_type',
        'answer_key',
        'answer_label',
        'answer_text',
        'definition_version',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(
            WebinarRegistration::class,
            'webinar_registration_id',
        );
    }
}