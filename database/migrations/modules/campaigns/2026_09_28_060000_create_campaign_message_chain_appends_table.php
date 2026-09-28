<?php

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_message_chain_appends', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            // Messaging migrations own the version table; retain a logical module bridge.
            $table->unsignedBigInteger('from_message_chain_version_id');
            $table->unsignedBigInteger('to_message_chain_version_id');
            $table->string('appended_step_key', 128);
            $table->foreignIdFor(User::class, 'created_by')
                ->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['campaign_id', 'from_message_chain_version_id'],
                'campaign_chain_append_source_unique',
            );
            $table->index('to_message_chain_version_id', 'campaign_chain_append_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_message_chain_appends');
    }
};