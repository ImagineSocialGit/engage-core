<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'phone',
        'status',
        'source',
        'subsource',
        'crm_status',
        'converted_at',
        'closed_at',
        'last_contacted_at',
        'last_activity_at',
        'assigned_to',
        'meta',
    ];

    protected $casts = [
        'converted_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_contacted_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'meta' => 'array',
    ];

    public function registrations(): HasMany
    {
        return $this->hasMany(WebinarRegistration::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'related');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(ContactTag::class);
    }

    public function mortgageProfiles(): HasMany
    {
        return $this->hasMany(ContactMortgageProfile::class);
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

    public function campaignEnrollments(): HasMany
    {
        return $this->hasMany(CampaignEnrollment::class);
    }

    public function inboundMessages(): MorphMany
    {
        return $this->morphMany(InboundMessage::class, 'sender');
    }
}