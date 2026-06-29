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

            $table->string('name');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('task_description')->nullable();

            $table->string('responsible_party')
                ->default(Task::RESPONSIBLE_PARTY_INTERNAL)
                ->index();

            $table->string('priority')->nullable()->index();
            $table->integer('due_offset_days')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['group_key', 'is_active']);
            $table->index(['responsible_party', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_templates');
    }
};