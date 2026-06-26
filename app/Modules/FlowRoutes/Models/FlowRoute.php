<?php

namespace App\Modules\FlowRoutes\Models;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_status_id',
        'name',
        'version',
        'meta',
    ];

    protected $casts = [
        'contact_status_id' => 'integer',
        'version' => 'integer',
        'meta' => 'array',
    ];

    public function contactStatus(): BelongsTo
    {
        return $this->belongsTo(ContactStatus::class);
    }

    public function flowRoutePoints(): HasMany
    {
        return $this->hasMany(FlowRoutePoint::class)->orderBy('sort_order');
    }

    public function generatedTasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}