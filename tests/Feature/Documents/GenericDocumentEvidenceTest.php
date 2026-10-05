<?php

namespace Tests\Feature\Documents;

use App\Models\User;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Models\DocumentUpload;
use App\Modules\Documents\Services\DocumentAttachmentLibrary;
use App\Modules\Documents\Services\DocumentEvidenceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenericDocumentEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_library_preserves_generic_subject_requirement_and_default_expiration(): void
    {
        Storage::fake('spaces');
        config()->set('documents.disk', 'spaces');

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-10-05 18:30:00 UTC'),
        );

        $subject = User::factory()->create();
        $requirement = DocumentRequirementDefinition::factory()
            ->active()
            ->create([
                'key' => 'insurance_certificate',
                'name' => 'Insurance Certificate',
                'requires_review' => false,
                'expires_after_days' => 30,
            ]);

        $upload = app(DocumentAttachmentLibrary::class)->store(
            file: UploadedFile::fake()->create(
                'insurance.pdf',
                10,
                'application/pdf',
            ),
            title: 'Insurance Certificate',
            subject: $subject,
            requirement: $requirement,
        );

        $this->assertNull($upload->contact_id);
        $this->assertSame($subject->getMorphClass(), $upload->subject_type);
        $this->assertSame((int) $subject->getKey(), (int) $upload->subject_id);
        $this->assertSame(
            (int) $requirement->getKey(),
            (int) $upload->document_requirement_definition_id,
        );
        $this->assertSame(
            DocumentUpload::REVIEW_STATUS_APPROVED,
            $upload->review_status,
        );
        $this->assertSame(
            '2026-11-04 18:30:00',
            $upload->expires_at?->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertTrue($upload->subject->is($subject));
        $this->assertTrue($upload->requirementDefinition->is($requirement));
    }

    public function test_explicit_valid_through_date_overrides_requirement_default(): void
    {
        Storage::fake('spaces');
        config()->set('documents.disk', 'spaces');

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-10-05 18:30:00 UTC'),
        );

        $subject = User::factory()->create();
        $requirement = DocumentRequirementDefinition::factory()
            ->active()
            ->create([
                'key' => 'professional_license',
                'name' => 'Professional License',
                'requires_review' => false,
                'expires_after_days' => 365,
            ]);

        $upload = app(DocumentAttachmentLibrary::class)->store(
            file: UploadedFile::fake()->create(
                'license.pdf',
                10,
                'application/pdf',
            ),
            subject: $subject,
            requirement: $requirement,
            expiresAt: CarbonImmutable::parse('2027-02-15 23:59:59 UTC'),
        );

        $this->assertSame(
            '2027-02-15 23:59:59',
            $upload->expires_at?->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_evidence_resolver_requires_approved_evidence_for_the_same_subject_valid_through_target_date(): void
    {
        Storage::fake('spaces');
        config()->set('documents.disk', 'spaces');

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-10-05 18:30:00 UTC'),
        );

        $subject = User::factory()->create();
        $otherSubject = User::factory()->create();

        $requirement = DocumentRequirementDefinition::factory()
            ->active()
            ->create([
                'key' => 'annual_certificate',
                'name' => 'Annual Certificate',
                'requires_review' => true,
                'expires_after_days' => null,
            ]);

        $expired = app(DocumentAttachmentLibrary::class)->store(
            file: UploadedFile::fake()->create(
                'expired.pdf',
                10,
                'application/pdf',
            ),
            subject: $subject,
            requirement: $requirement,
            expiresAt: CarbonImmutable::parse('2026-10-10 23:59:59 UTC'),
        );
        $expired->forceFill([
            'status' => DocumentUpload::STATUS_APPROVED,
            'review_status' => DocumentUpload::REVIEW_STATUS_APPROVED,
            'approved_at' => CarbonImmutable::now('UTC'),
        ])->save();

        $other = app(DocumentAttachmentLibrary::class)->store(
            file: UploadedFile::fake()->create(
                'other.pdf',
                10,
                'application/pdf',
            ),
            subject: $otherSubject,
            requirement: $requirement,
            expiresAt: CarbonImmutable::parse('2027-10-05 23:59:59 UTC'),
        );
        $other->forceFill([
            'status' => DocumentUpload::STATUS_APPROVED,
            'review_status' => DocumentUpload::REVIEW_STATUS_APPROVED,
            'approved_at' => CarbonImmutable::now('UTC'),
        ])->save();

        $current = app(DocumentAttachmentLibrary::class)->store(
            file: UploadedFile::fake()->create(
                'current.pdf',
                10,
                'application/pdf',
            ),
            subject: $subject,
            requirement: $requirement,
            expiresAt: CarbonImmutable::parse('2027-10-05 23:59:59 UTC'),
        );

        $resolver = app(DocumentEvidenceResolver::class);
        $validThrough = CarbonImmutable::parse('2026-10-15 12:00:00 UTC');

        $this->assertFalse(
            $resolver->satisfies(
                subject: $subject,
                requirementKey: $requirement->key,
                validThrough: $validThrough,
            ),
        );

        $current->forceFill([
            'status' => DocumentUpload::STATUS_APPROVED,
            'review_status' => DocumentUpload::REVIEW_STATUS_APPROVED,
            'approved_at' => CarbonImmutable::now('UTC'),
        ])->save();

        $resolved = $resolver->latestApproved(
            subject: $subject,
            requirementKey: $requirement->key,
            validThrough: $validThrough,
        );

        $this->assertTrue($resolved?->is($current));
        $this->assertFalse($resolved?->is($expired) ?? true);
        $this->assertFalse($resolved?->is($other) ?? true);
    }
}