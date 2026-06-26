<?php

namespace App\Modules\Core\Models;

use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Contact extends Model
{
    use HasFactory;

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'phone',
        'source',
        'subsource',
        'last_contacted_at',
        'last_activity_at',
        'meta',
    ];

    protected $casts = [
        'last_contacted_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'meta' => 'array',
    ];

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(ContactTag::class);
    }

    public function workflowProfile(): HasOne
    {
        return $this->hasOne(ContactWorkflowProfile::class);
    }

    public function scheduledMessages(): MorphMany
    {
        return $this->morphMany(ScheduledMessage::class, 'recipient');
    }

    public function messageConsents(): HasMany
    {
        return $this->hasMany(MessageConsent::class);
    }

    public function consentRevocations(): HasMany
    {
        return $this->hasMany(ConsentRevocation::class);
    }

    public function inboundMessages(): MorphMany
    {
        return $this->morphMany(InboundMessage::class, 'sender');
    }
}