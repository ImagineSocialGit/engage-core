<?php

namespace App\Support\Modules\Migrations;

use Illuminate\Database\Eloquent\Model;

final class ModuleInstallation extends Model
{
    public const STATUS_INSTALLING = 'installing';

    public const STATUS_INSTALLED = 'installed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'module_installations';

    protected $primaryKey = 'module_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'module_key',
        'status',
        'schema_version',
        'manifest_hash',
        'installed_at',
        'last_migrated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'installed_at' => 'immutable_datetime',
            'last_migrated_at' => 'immutable_datetime',
        ];
    }
}