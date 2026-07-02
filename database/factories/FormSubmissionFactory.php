<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<FormSubmission>
 */
class FormSubmissionFactory extends Factory
{
    protected $model = FormSubmission::class;

    public function definition(): array
    {
        return [
            'form_definition_id' => FormDefinition::factory(),
            'form_version_id' => FormVersion::factory(),
            'contact_id' => Contact::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'status' => FormSubmission::STATUS_SUBMITTED,
            'review_status' => FormSubmission::REVIEW_STATUS_PENDING,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'reviewed_by_type' => null,
            'reviewed_by_id' => null,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature test',
            'payload' => [],
            'raw_payload' => null,
            'meta' => null,
        ];
    }

    public function forVersion(FormVersion $version): self
    {
        return $this->state([
            'form_definition_id' => $version->form_definition_id,
            'form_version_id' => $version->id,
        ]);
    }

    public function forSubject(Model $subject): self
    {
        return $this->state([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function reviewed(string $reviewStatus = FormSubmission::REVIEW_STATUS_APPROVED): self
    {
        return $this->state([
            'review_status' => $reviewStatus,
            'reviewed_at' => now(),
        ]);
    }

    public function reviewedBy(Model $reviewer, string $reviewStatus = FormSubmission::REVIEW_STATUS_APPROVED): self
    {
        return $this->state([
            'review_status' => $reviewStatus,
            'reviewed_at' => now(),
            'reviewed_by_type' => $reviewer->getMorphClass(),
            'reviewed_by_id' => $reviewer->getKey(),
        ]);
    }
}
