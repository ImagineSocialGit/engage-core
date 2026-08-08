<?php

use App\Modules\Forms\Models\FormSubmission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submission_values', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(FormSubmission::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('field_key')->index();
            $table->string('field_label')->nullable();
            $table->string('field_type')->nullable()->index();

            $table->json('value')->nullable();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 16, 4)->nullable()->index();
            $table->boolean('value_boolean')->nullable()->index();
            $table->date('value_date')->nullable()->index();
            $table->timestamp('value_datetime')->nullable()->index();

            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['form_submission_id', 'field_key'], 'form_submission_values_submission_field_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submission_values');
    }
};