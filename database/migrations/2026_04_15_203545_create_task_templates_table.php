<?php

use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();

            $table->string('key')->unique();
            $table->string('group_key')->index();
            $table->string('source')->default('preset')->index();
            $table->string('source_version')->nullable();
            $table->string('owner_group')->nullable()->index();
            $table->string('category')->nullable()->index();

            $table->string('name');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('task_description')->nullable();

            $table->nullableMorphs('assigned_to');
            $table->string('assigned_to_strategy')->nullable()->index();

            $table->string('responsible_party')
                ->default(Task::RESPONSIBLE_PARTY_INTERNAL)
                ->index();
            $table->nullableMorphs('responsible');

            $table->string('priority')->nullable()->index();
            $table->integer('due_offset_minutes')->nullable();

            $table->json('related_subject')->nullable();
            $table->json('defaults')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_customized')->default(false)->index();
            $table->timestamp('customized_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['group_key', 'is_active']);
            $table->index(['source', 'source_version']);
            $table->index(['owner_group', 'is_active']);
            $table->index(['responsible_party', 'is_active']);
            $table->index(['is_customized', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};
