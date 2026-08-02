<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_state_resume_items', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 80);
            $table->string('source_table', 128);
            $table->string('source_record_id', 191);
            $table->string('original_status', 80);
            $table->string('state', 32)->default('pending');
            $table->string('result_code', 120)->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_table', 'source_record_id'],
                'project_state_resume_source_unique',
            );
            $table->index(
                ['category', 'state'],
                'project_state_resume_category_state_index',
            );
            $table->index('state', 'project_state_resume_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_state_resume_items');
    }
};