<?php

use App\Modules\Core\Models\Contact;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(FormDefinition::class)
                ->constrained()
                ->restrictOnDelete();

            $table->foreignIdFor(FormVersion::class)
                ->constrained()
                ->restrictOnDelete();

            $table->foreignIdFor(Contact::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->nullableMorphs('subject');

            $table->string('status')->default('submitted')->index();
            $table->string('review_status')->default('pending')->index();

            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->nullableMorphs('reviewed_by');

            $table->string('source')->default('manual')->index();
            $table->string('provider')->nullable()->index();
            $table->string('external_id')->nullable()->index();

            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            $table->json('payload')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['form_definition_id', 'status'], 'form_submissions_definition_status_index');
            $table->index(['form_version_id', 'status'], 'form_submissions_version_status_index');
            $table->index(['contact_id', 'status'], 'form_submissions_contact_status_index');
            $table->index(['review_status', 'submitted_at'], 'form_submissions_review_submitted_index');
            $table->index(['provider', 'external_id'], 'form_submissions_provider_external_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};