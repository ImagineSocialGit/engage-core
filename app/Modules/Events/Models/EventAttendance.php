<?php

namespace App\Modules\Events\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Events\Enums\EventAttendanceStatus;
use Database\Factories\EventAttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventAttendance extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): EventAttendanceFactory
    {
        return EventAttendanceFactory::new();
    }

    protected $fillable = [
        'event_id',
        'contact_id',
        'status',
        'observed_at',
        'source_key',
        'source_reference',
    ];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'contact_id' => 'integer',
            'status' => EventAttendanceStatus::class,
            'observed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}