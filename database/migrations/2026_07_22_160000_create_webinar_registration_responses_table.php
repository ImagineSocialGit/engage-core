<?php

use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_registration_responses', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(WebinarRegistration::class);

            $table->string('question_key', 100);
            $table->string('question_label');
            $table->string('question_type', 50);
            $table->string('answer_key', 100);
            $table->string('answer_label');
            $table->text('answer_text')->nullable();
            $table->string('definition_version', 50);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->foreign(
                'webinar_registration_id',
                'webinar_reg_response_registration_fk',
            )
                ->references('id')
                ->on('webinar_registrations')
                ->cascadeOnDelete();

            $table->unique(
                ['webinar_registration_id', 'question_key'],
                'webinar_reg_response_question_unique',
            );

            $table->index(
                ['question_key', 'answer_key'],
                'webinar_reg_response_question_answer_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_registration_responses');
    }
};