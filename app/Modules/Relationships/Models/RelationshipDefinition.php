<?php

namespace App\Modules\Relationships\Models;

use Illuminate\Database\Eloquent\Model;

class RelationshipDefinition extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'singular', 'plural', 'visible', 'sort_order'];

    protected $casts = ['visible' => 'boolean', 'sort_order' => 'integer'];
}