<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContactWorkflowProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'contact_status_id',
        'assigned_to_type',
        'assigned_to_id',
        'last_status_changed_at',
        'meta',
    ];

    protected $casts = [
        'contact_id' => 'integer',
        'contact_status_id' => 'integer',
        'assigned_to_id' => 'integer',
        'last_status_changed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function contactStatus(): BelongsTo
    {
        return $this->belongsTo(ContactStatus::class);
    }

    public function assignedTo(): MorphTo
    {
        return $this->morphTo();
    }
}