<?php

namespace App\Support\AutomationEvents\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationEventConsumerReceipt extends Model
{
    protected $fillable = [
        'event_id',
        'consumer',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}