<?php

namespace App\Modules\Campaigns\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CampaignMessageChainAppend extends Model
{
    protected $fillable = [
        'campaign_id',
        'from_message_chain_version_id',
        'to_message_chain_version_id',
        'appended_step_key',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'from_message_chain_version_id' => 'integer',
            'to_message_chain_version_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}