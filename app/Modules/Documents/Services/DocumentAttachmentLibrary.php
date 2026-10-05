<?php

namespace App\Modules\Documents\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Documents\Models\DocumentRequest;
use App\Modules\Documents\Models\DocumentRequirementDefinition;
use App\Modules\Documents\Models\DocumentUpload;
use App\Support\ModuleIntegrations\Documents\Contracts\DocumentAttachmentSource;
use App\Support\ModuleIntegrations\Documents\DocumentAttachment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DocumentAttachmentLibrary implements DocumentAttachmentSource
{
    public function store(
        UploadedFile $file,
        ?Contact $contact = null,
        ?DocumentRequest $request = null,
        ?string $title = null,
        ?Model $uploadedBy = null,
        ?Model $subject = null,
        ?DocumentRequirementDefinition $requirement = null,
        ?CarbonInterface $expiresAt = null,
    ): DocumentUpload {
        if ($request !== null && $contact !== null && $request->contact_id !== $contact->getKey()) {
            throw new RuntimeException('The request does not belong to the selected contact.');
        }

        if ($subject !== null && (! $subject->exists || $subject->getKey() === null)) {
            throw new RuntimeException('The document subject must be persisted before upload.');
        }

        if ($requirement !== null && (! $requirement->exists || $requirement->getKey() === null)) {
            throw new RuntimeException('The document requirement must be persisted before upload.');
        }

        if ($request !== null && $requirement !== null
            && $request->document_requirement_definition_id !== null
            && (int) $request->document_requirement_definition_id !== (int) $requirement->getKey()) {
            throw new RuntimeException('The request belongs to another document requirement.');
        }

        $requestSubjectType = is_string($request?->subject_type) && trim($request->subject_type) !== ''
            ? $request->subject_type
            : null;
        $requestSubjectId = $request?->subject_id !== null
            ? (int) $request->subject_id
            : null;

        if ($requestSubjectType !== null && $requestSubjectId !== null && $subject !== null
            && ($requestSubjectType !== $subject->getMorphClass()
                || $requestSubjectId !== (int) $subject->getKey())) {
            throw new RuntimeException('The request belongs to another document subject.');
        }

        $contact ??= $request?->contact;

        if ($contact === null && $subject instanceof Contact) {
            $contact = $subject;
        }

        $requirement ??= $request?->requirementDefinition;

        $subjectType = $subject?->getMorphClass() ?? $requestSubjectType;
        $subjectId = $subject?->getKey() ?? $requestSubjectId;

        $mime = strtolower(trim((string) $file->getMimeType()));
        $allowed = config('documents.allowed_mime_types', []);

        if (! in_array($mime, is_array($allowed) ? $allowed : [], true)) {
            throw new RuntimeException('This document file type is not allowed.');
        }

        $size = $file->getSize();
        $limit = max(1, (int) config('documents.max_upload_kilobytes', 307200)) * 1024;

        if ($requirement?->max_file_size_kb !== null) {
            $limit = min($limit, (int) $requirement->max_file_size_kb * 1024);
        }

        if (! is_int($size) || $size <= 0 || $size > $limit) {
            throw new RuntimeException('The document exceeds its allowed size or is empty.');
        }

        $accepted = $requirement?->accepted_mime_types;

        if (is_array($accepted) && $accepted !== [] && ! in_array($mime, $accepted, true)) {
            throw new RuntimeException('This file type is not accepted for the document request.');
        }

        if ($request !== null && $requirement?->allows_multiple_uploads === false
            && $request->uploads()
                ->whereNotIn('status', [
                    DocumentUpload::STATUS_SUPERSEDED,
                    DocumentUpload::STATUS_DELETED,
                ])
                ->exists()) {
            throw new RuntimeException('This request already has a document.');
        }

        $disk = trim((string) config('documents.disk', 'spaces'));
        $directory = trim((string) config('documents.directory', 'documents'), '/');

        if ($disk === '' || $directory === '') {
            throw new RuntimeException('Private document storage is not configured.');
        }

        $submittedAt = CarbonImmutable::now('UTC');
        $resolvedExpiresAt = $expiresAt instanceof CarbonInterface
            ? CarbonImmutable::instance($expiresAt)->utc()
            : null;

        if ($resolvedExpiresAt === null
            && is_int($requirement?->expires_after_days)
            && $requirement->expires_after_days > 0) {
            $resolvedExpiresAt = $submittedAt->addDays($requirement->expires_after_days);
        }

        $uuid = (string) Str::uuid();
        $extension = strtolower((string) ($file->guessExtension() ?: 'bin'));
        $extension = preg_match('/^[a-z0-9]{1,12}$/', $extension) === 1 ? $extension : 'bin';

        $path = Storage::disk($disk)->putFileAs(
            $directory.'/'.$uuid,
            $file,
            $uuid.'.'.$extension,
            ['visibility' => 'private'],
        );

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The document could not be stored.');
        }

        try {
            return DB::transaction(function () use (
                $file,
                $contact,
                $request,
                $title,
                $uploadedBy,
                $subjectType,
                $subjectId,
                $requirement,
                $disk,
                $path,
                $mime,
                $size,
                $extension,
                $submittedAt,
                $resolvedExpiresAt,
            ): DocumentUpload {
                $upload = DocumentUpload::query()->create([
                    'document_request_id' => $request?->getKey(),
                    'document_requirement_definition_id' => $requirement?->getKey(),
                    'contact_id' => $contact?->getKey(),
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'uploaded_by_type' => $uploadedBy?->getMorphClass(),
                    'uploaded_by_id' => $uploadedBy?->getKey(),
                    'title' => Str::limit(
                        trim((string) $title) !== ''
                            ? trim((string) $title)
                            : $file->getClientOriginalName(),
                        255,
                        '',
                    ),
                    'status' => DocumentUpload::STATUS_UPLOADED,
                    'review_status' => $requirement?->requires_review === false
                        ? DocumentUpload::REVIEW_STATUS_APPROVED
                        : DocumentUpload::REVIEW_STATUS_PENDING,
                    'disk' => $disk,
                    'path' => $path,
                    'original_filename' => Str::limit($file->getClientOriginalName(), 255, ''),
                    'mime_type' => $mime,
                    'extension' => $extension,
                    'size_bytes' => $size,
                    'checksum' => hash_file('sha256', $file->getRealPath()),
                    'storage_visibility' => DocumentUpload::STORAGE_VISIBILITY_PRIVATE,
                    'submitted_at' => $submittedAt,
                    'expires_at' => $resolvedExpiresAt,
                ]);

                if ($request !== null) {
                    $request->forceFill([
                        'first_uploaded_at' => $request->first_uploaded_at ?? $submittedAt,
                        'last_uploaded_at' => $submittedAt,
                        'status' => DocumentRequest::STATUS_UPLOADED,
                    ])->save();
                }

                return $upload;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    public function find(int $documentUploadId): ?DocumentAttachment
    {
        $upload = DocumentUpload::query()->find($documentUploadId);

        if ($upload === null || $upload->trashed()
            || in_array($upload->status, [DocumentUpload::STATUS_DELETED, DocumentUpload::STATUS_ARCHIVED], true)
            || $upload->storage_visibility !== DocumentUpload::STORAGE_VISIBILITY_PRIVATE
            || ! is_string($upload->disk) || ! is_string($upload->path)
            || ! is_string($upload->mime_type) || ! is_string($upload->original_filename)) {
            return null;
        }

        return new DocumentAttachment(
            id: (int) $upload->getKey(),
            contactId: $upload->contact_id !== null ? (int) $upload->contact_id : null,
            disk: $upload->disk,
            path: $upload->path,
            filename: basename($upload->original_filename),
            mimeType: $upload->mime_type,
            sizeBytes: (int) $upload->size_bytes,
        );
    }
}