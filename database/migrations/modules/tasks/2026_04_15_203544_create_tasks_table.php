<?php

use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->nullableMorphs('assigned_to');

            $table->string('responsible_party')->default('internal')->index();
            $table->nullableMorphs('responsible');

            $table->foreignIdFor(TaskTemplate::class)
                ->nullable()
                ->constrained('task_templates')
                ->nullOnDelete();

            $table->string('task_template_key')->nullable()->index();

            $table->string('source')->default('manual')->index();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status')->default('open')->index();
            $table->string('priority')->nullable()->index();

            $table->timestamp('due_at')->nullable()->index();

            $table->timestamp('completed_at')->nullable()->index();

            $table->timestamp('canceled_at')->nullable()->index();
            $table->string('canceled_reason')->nullable();

            $table->timestamp('archived_at')->nullable()->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['assigned_to_type', 'assigned_to_id', 'status'], 'tasks_assigned_to_status_index');
            $table->index(['responsible_party', 'status', 'archived_at'], 'tasks_responsible_party_status_index');
            $table->index(['status', 'archived_at', 'due_at'], 'tasks_status_archived_due_index');
            $table->index(['task_template_id', 'status'], 'tasks_template_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
