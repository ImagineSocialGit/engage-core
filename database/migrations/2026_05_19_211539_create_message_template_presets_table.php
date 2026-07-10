<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_template_presets', function (Blueprint $table) {
            $table->id();

            $table->string('key', 191)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();

            $table->string('channel', 32)->index();
            $table->string('purpose', 32)->index();
            $table->string('scope', 96)->index();
            $table->string('message_type', 128)->nullable()->index();

            $table->string('payload_class', 191);
            $table->string('queue', 96)->nullable()->index();

            $table->json('dispatch_keys')->nullable();
            $table->json('payload');
            $table->json('tokens')->nullable();

            $table->string('status', 32)->default('active')->index();
            $table->boolean('is_active')->default(true)->index();

            $table->string('source', 64)->nullable()->index();
            $table->string('source_config_path', 191)->nullable()->index();
            $table->unsignedInteger('source_version')->nullable();
            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index([
                'channel',
                'purpose',
                'scope',
                'message_type',
            ], 'mtp_msg_ctx_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_template_presets');
    }
};
