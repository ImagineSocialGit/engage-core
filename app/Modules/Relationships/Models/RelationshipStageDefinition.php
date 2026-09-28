<?php

namespace App\Modules\Relationships\Models;

use Illuminate\Database\Eloquent\Model;

class RelationshipStageDefinition extends Model
{
    protected $fillable = ['relationship_key', 'key', 'label', 'sort_order', 'active'];

    protected $casts = ['sort_order' => 'integer', 'active' => 'boolean'];
}