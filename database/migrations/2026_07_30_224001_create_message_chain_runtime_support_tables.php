<?php

use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->foreignIdFor(MessageTemplateVersion::class)
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
        });

        Schema::create('message_chain_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(MessageChainVersion::class)
                ->constrained()
                ->restrictOnDelete();
            $table->morphs('recipient');
            $table->nullableMorphs('context');
            $table->nullableMorphs('origin');
            $table->foreignIdFor(MessageChainStep::class, 'current_message_chain_step_id')
                ->nullable()
                ->constrained('message_chain_steps')
                ->nullOnDelete();
            $table->timestamp('next_action_at')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestamp('started_at');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->string('exit_reason_code', 96)->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(
                ['status', 'next_action_at'],
                'message_chain_enrollments_due_index',
            );
            $table->index(
                ['recipient_type', 'recipient_id', 'status'],
                'message_chain_enrollments_recipient_status_index',
            );
            $table->index(
                ['message_chain_version_id', 'status'],
                'message_chain_enrollments_version_status_index',
            );
        });

        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->foreignIdFor(MessageChainEnrollment::class)
                ->nullable()
                ->constrained()
                ->restrictOnDelete();
            $table->foreignIdFor(MessageChainStepVariant::class)
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            $table->index(
                [
                    'message_chain_enrollment_id',
                    'message_chain_step_variant_id',
                ],
                'scheduled_messages_chain_enrollment_variant_index',
            );
        });

        Schema::create('scheduled_message_render_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(ScheduledMessage::class)
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->json('values');
            $table->char('content_hash', 64);
            $table->timestamp('rendered_at');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index('content_hash');
        });

        Schema::create('scheduled_message_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(ScheduledMessage::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(MessageTemplateVersion::class)
                ->constrained()
                ->restrictOnDelete();
            $table->string('role', 64);
            $table->string('intent_key', 191)->nullable()->index();
            $table->foreignIdFor(MessageConsent::class)
                ->nullable()
                ->constrained('message_consents')
                ->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('placement_key', 96)->nullable();
            $table->timestamps();

            $table->unique(
                ['scheduled_message_id', 'sort_order'],
                'scheduled_message_components_message_order_unique',
            );
            $table->index(
                ['scheduled_message_id', 'role'],
                'scheduled_message_components_message_role_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_message_components');
        Schema::dropIfExists('scheduled_message_render_contexts');

        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->dropIndex(
                'scheduled_messages_chain_enrollment_variant_index',
            );
            $table->dropConstrainedForeignId(
                'message_chain_step_variant_id',
            );
            $table->dropConstrainedForeignId(
                'message_chain_enrollment_id',
            );
        });

        Schema::dropIfExists('message_chain_enrollments');

        Schema::table('scheduled_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('message_template_version_id');
        });
    }
};