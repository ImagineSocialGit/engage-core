<?php

namespace App\Modules\PetServices\Models;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pet extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_DECEASED = 'deceased';

    public const STATUS_OPTIONS = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_DECEASED,
    ];

    protected $fillable = [
        'name',
        'species',
        'breed',
        'sex',
        'birth_date',
        'birth_date_is_estimated',
        'is_spayed_neutered',
        'status',
        'source',
        'meta',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'birth_date_is_estimated' => 'boolean',
        'is_spayed_neutered' => 'boolean',
        'meta' => 'array',
    ];

    public function contactLinks(): HasMany
    {
        return $this->hasMany(PetContactLink::class);
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            'pet_contact_links',
        )
            ->withPivot([
                'role',
                'is_primary',
                'is_active',
                'started_at',
                'ended_at',
                'meta',
            ])
            ->withTimestamps();
    }

    public function trainingGoals(): HasMany
    {
        return $this->hasMany(PetTrainingGoal::class);
    }

    public function behaviorNotes(): HasMany
    {
        return $this->hasMany(PetBehaviorNote::class);
    }

    public function vaccinations(): HasMany
    {
        return $this->hasMany(PetVaccination::class);
    }
}