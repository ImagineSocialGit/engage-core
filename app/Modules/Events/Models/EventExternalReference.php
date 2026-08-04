<?php

namespace App\Modules\Events\Models;

use Database\Factories\EventExternalReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventExternalReference extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): EventExternalReferenceFactory
    {
        return EventExternalReferenceFactory::new();
    }

    protected $fillable = [
        'event_id',
        'provider_key',
        'reference_type',
        'external_id',
        'url',
        'label',
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