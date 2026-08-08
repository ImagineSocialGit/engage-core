<?php

use App\Modules\InternalNotifications\Models\TeamMember;
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
            $table->string('purpose')->index();
            $table->string('scope')->nullable()->index();

            $table->boolean('is_enabled')->default(true)->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(
                ['team_member_id', 'channel', 'purpose', 'scope'],
                'team_member_notification_preference_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_member_notification_preferences');
    }
};