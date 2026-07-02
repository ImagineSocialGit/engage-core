<?php

namespace Database\Factories;

use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentReviewEvent;
use App\Modules\Documents\Models\DocumentUpload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<DocumentReviewEvent>
 */
class DocumentReviewEventFactory extends Factory
{
    protected $model = DocumentReviewEvent::class;

    public function definition(): array
    {
        return [
            'document_request_id' => DocumentRequest::factory(),
            'document_upload_id' => DocumentUpload::factory(),
            'actor_type' => null,
            'actor_id' => null,
            'event' => DocumentReviewEvent::EVENT_REVIEWED,
            'from_status' => null,
            'to_status' => null,
            'reason' => null,
            'notes' => fake()->optional()->sentence(),
            'occurred_at' => now(),
            'meta' => null,
        ];
    }

    public function forRequest(DocumentRequest $request): self
    {
        return $this->state([
            'document_request_id' => $request->id,
        ]);
    }

    public function forUpload(DocumentUpload $upload): self
    {
        return $this->state([
            'document_upload_id' => $upload->id,
        ]);
    }

    public function byActor(Model $actor): self
    {
        return $this->state([
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
        ]);
    }

    public function approved(): self
    {
        return $this->state([
            'event' => DocumentReviewEvent::EVENT_APPROVED,
            'to_status' => DocumentUpload::STATUS_APPROVED,
        ]);
    }

    public function rejected(string $reason = 'unclear_document'): self
    {
        return $this->state([
            'event' => DocumentReviewEvent::EVENT_REJECTED,
            'to_status' => DocumentUpload::STATUS_REJECTED,
            'reason' => $reason,
        ]);
    }
}
