<?php

use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentUpload;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_review_events', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(DocumentRequest::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(DocumentUpload::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->nullableMorphs('actor');

            $table->string('event')->index();
            $table->string('from_status')->nullable()->index();
            $table->string('to_status')->nullable()->index();
            $table->string('reason')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['document_request_id', 'event'], 'document_review_events_request_event_index');
            $table->index(['document_upload_id', 'event'], 'document_review_events_upload_event_index');
            $table->index(['event', 'occurred_at'], 'document_review_events_event_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_review_events');
    }
};
