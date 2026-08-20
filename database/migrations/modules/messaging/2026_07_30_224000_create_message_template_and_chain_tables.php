<?php

use App\Models\User;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 191)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('channel', 32)->index();
            $table->string('status', 32)->default('active')->index();
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->string('source', 64)->nullable()->index();
            $table->string('source_version', 64)->nullable();
            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable()->index();
            $table->timestamps();

            $table->index(
                ['channel', 'status'],
                'message_templates_channel_status_index',
            );
        });

        Schema::create('message_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(MessageTemplate::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('subject')->nullable();
            $table->json('content');
            $table->string('renderer_key', 96);
            $table->string('renderer_version', 64);
            $table->char('content_hash', 64);
            $table->foreignIdFor(User::class, 'created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['message_template_id', 'version'],
                'message_template_versions_template_version_unique',
            );
            $table->unique(
                ['message_template_id', 'content_hash'],
                'message_template_versions_template_hash_unique',
            );
            $table->index('content_hash');
        });

        Schema::table('message_templates', function (Blueprint $table): void {
            $table->foreign('current_version_id')
                ->references('id')
                ->on('message_template_versions')
                ->nullOnDelete();
        });

        Schema::create('message_chains', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 191)->unique();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->string('source', 64)->nullable()->index();
            $table->string('source_version', 64)->nullable();
            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('message_chain_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(MessageChain::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('exit_conditions')->nullable();
            $table->char('content_hash', 64);
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignIdFor(User::class, 'created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['message_chain_id', 'version'],
                'message_chain_versions_chain_version_unique',
            );
            $table->unique(
                ['message_chain_id', 'content_hash'],
                'message_chain_versions_chain_hash_unique',
            );
            $table->index('content_hash');
        });

        Schema::table('message_chains', function (Blueprint $table): void {
            $table->foreign('current_version_id')
                ->references('id')
                ->on('message_chain_versions')
                ->nullOnDelete();
        });

        Schema::create('message_chain_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(MessageChainVersion::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->string('key', 128);
            $table->string('name', 191)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('timing_type', 32)->default('immediate');
            $table->string('anchor_key', 96)->nullable();
            $table->integer('offset_seconds')->default(0);
            $table->smallInteger('day_offset')->default(0);
            $table->time('local_time')->nullable();
            $table->string('variant_strategy', 32)->default('first_available');
            $table->string('advance_policy', 32)->default('all_terminal');
            $table->json('conditions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(
                ['message_chain_version_id', 'key'],
                'message_chain_steps_version_key_unique',
            );
            $table->index(
                ['message_chain_version_id', 'is_active', 'sort_order'],
                'message_chain_steps_version_active_order_index',
            );
        });

        Schema::create('message_chain_step_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(MessageChainStep::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->string('key', 128);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignIdFor(MessageTemplateVersion::class)
                ->constrained()
                ->restrictOnDelete();
            $table->string('channel', 32)->index();
            $table->string('purpose', 32)->index();
            $table->string('scope', 120)->index();
            $table->string('message_type', 128)->index();
            $table->string('reply_profile_key', 96)->nullable();
            $table->string('queue', 96)->nullable()->index();
            $table->json('dependency_policy')->nullable();
            $table->json('conditions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(
                ['message_chain_step_id', 'key'],
                'message_chain_step_variants_step_key_unique',
            );
            $table->index(
                ['message_chain_step_id', 'is_active', 'sort_order'],
                'message_chain_step_variants_active_order_index',
            );
            $table->index(
                ['channel', 'purpose', 'scope', 'message_type'],
                'message_chain_step_variants_delivery_context_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_chain_step_variants');
        Schema::dropIfExists('message_chain_steps');

        Schema::table('message_chains', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('message_chain_versions');
        Schema::dropIfExists('message_chains');

        Schema::table('message_templates', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('message_template_versions');
        Schema::dropIfExists('message_templates');
    }
};