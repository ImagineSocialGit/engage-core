<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->foreignId('message_template_id')
                ->nullable()
                ->after('user_id')
                ->unique()
                ->constrained('message_templates')
                ->restrictOnDelete();
            $table->foreignId('message_template_version_id')
                ->nullable()
                ->after('message_template_id')
                ->constrained('message_template_versions')
                ->restrictOnDelete();
            $table->dropColumn('payload');
        });

        Schema::table('broadcast_recipients', function (Blueprint $table): void {
            $table->foreignId('scheduled_message_id')
                ->nullable()
                ->after('status')
                ->unique()
                ->constrained('scheduled_messages')
                ->nullOnDelete();
            $table->dropColumn('scheduled_message_ids');
        });
    }

    public function down(): void
    {
        Schema::table('broadcast_recipients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('scheduled_message_id');
            $table->json('scheduled_message_ids')->nullable()->after('status');
        });

        Schema::table('broadcasts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('message_template_version_id');
            $table->dropConstrainedForeignId('message_template_id');
            $table->json('payload')->nullable()->after('send_at');
        });
    }
};