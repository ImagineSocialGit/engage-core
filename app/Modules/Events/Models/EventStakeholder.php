<?php

namespace App\Modules\Events\Models;

use Database\Factories\EventStakeholderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventStakeholder extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): EventStakeholderFactory
    {
        return EventStakeholderFactory::new();
    }

    protected $fillable = [
        'event_id',
        'role_key',
        'name',
        'organization',
        'email',
        'phone',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}