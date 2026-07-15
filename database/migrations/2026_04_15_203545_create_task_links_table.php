<?php

use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_links', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Task::class)
                ->constrained('tasks')
                ->cascadeOnDelete();

            $table->morphs('linkable');
            $table->string('role', 50)->index();

            $table->timestamps();

            $table->unique(
                ['task_id', 'linkable_type', 'linkable_id', 'role'],
                'task_links_task_linkable_role_unique',
            );

            $table->index(
                ['linkable_type', 'linkable_id', 'role', 'task_id'],
                'task_links_linkable_role_task_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_links');
    }
};
