<?php

use App\Models\TeamMember;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_member_notification_preferences', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(TeamMember::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('channel')->index();
            $table->string('notification_type')->index();

            $table->boolean('enabled')
                ->default(true)
                ->index();

            $table->timestamps();

            $table->unique(
                ['team_member_id', 'channel', 'notification_type'],
                'team_member_notification_preference_unique'
            );

            $table->index(
                ['channel', 'notification_type', 'enabled'],
                'team_member_notification_preference_lookup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_member_notification_preferences');
    }
};