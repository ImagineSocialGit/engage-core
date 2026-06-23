<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $fillable = [
        'assigned_to_type',
        'assigned_to_id',
        'related_type',
        'related_id',
        'title',
        'description',
        'status',
        'due_at',
        'completed_at',
    ];

    protected $casts = [
        'assigned_to_id' => 'integer',
        'related_id' => 'integer',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function assignedTo(): MorphTo
    {
        return $this->morphTo();
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}