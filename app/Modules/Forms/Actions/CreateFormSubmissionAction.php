<?php

namespace App\Modules\Forms\Actions;

use App\Modules\Forms\Data\FormSubmissionInput;
use App\Modules\Forms\Data\FormSubmissionResult;
use App\Modules\Forms\Data\NormalizedFormSubmission;
use App\Modules\Forms\Data\PublishedForm;
use App\Modules\Forms\Exceptions\FormSubmissionReplayConflictException;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Services\FormSubmissionContactMapper;
use App\Modules\Forms\Services\FormSubmissionValidator;
use App\Modules\Forms\Services\PublishedFormResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use JsonException;

final class CreateFormSubmissionAction
{
    public function __construct(
        private readonly PublishedFormResolver $forms,
        private readonly FormSubmissionValidator $validator,
        private readonly FormSubmissionContactMapper $contacts,
    ) {}

    public function handle(FormSubmissionInput $input): FormSubmissionResult
    {
        $fingerprint = $this->fingerprint($input);

        if ($input->hasExternalIdentity()) {
            $existing = $this->existingSubmission($input);

            if ($existing instanceof FormSubmission) {
                return $this->replay($existing, $input, $fingerprint);
            }
        }

        $form = $this->forms->require(
            key: $input->formKey,
            publicOnly: $input->publicOnly,
        );
        $this->validator->validateConfiguration($form);
        $this->contacts->validateConfiguration($form);
        $normalized = $this->validator->validate($form, $input->values);

        try {
            return DB::transaction(function () use (
                $input,
                $form,
                $normalized,
                $fingerprint,
            ): FormSubmissionResult {
                if ($input->hasExternalIdentity()) {
                    $existing = $this->existingSubmission($input, lock: true);

                    if ($existing instanceof FormSubmission) {
                        return $this->replay($existing, $input, $fingerprint);
                    }
                }

                return $this->create(
                    input: $input,
                    form: $form,
                    normalized: $normalized,
                    fingerprint: $fingerprint,
                );
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! $input->hasExternalIdentity() || ! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = $this->existingSubmission($input);

            if (! $existing instanceof FormSubmission) {
                throw $exception;
            }

            return $this->replay($existing, $input, $fingerprint);
        }
    }

    private function create(
        FormSubmissionInput $input,
        PublishedForm $form,
        NormalizedFormSubmission $normalized,
        string $fingerprint,
    ): FormSubmissionResult {
        $contact = $this->contacts->map($form, $normalized->payload);
        $runtimeMeta = [
            'runtime_version' => 1,
        ];

        if ($input->hasExternalIdentity()) {
            $runtimeMeta['idempotency_fingerprint'] = $fingerprint;
        }

        $submission = FormSubmission::query()->create([
            'form_definition_id' => $form->definitionId,
            'form_version_id' => $form->versionId,
            'contact_id' => $contact?->getKey(),
            'status' => FormSubmission::STATUS_SUBMITTED,
            'review_status' => FormSubmission::REVIEW_STATUS_PENDING,
            'submitted_at' => now(),
            'source' => $input->source,
            'provider' => $input->provider,
            'external_id' => $input->externalId,
            'ip_address' => $input->ipAddress,
            'user_agent' => $input->userAgent,
            'payload' => $normalized->payload,
            'raw_payload' => $input->rawPayload ?? $input->values,
            'meta' => [
                ...$input->meta,
                FormSubmissionInput::INTERNAL_META_KEY => $runtimeMeta,
            ],
        ]);

        foreach ($normalized->values as $value) {
            $submission->values()->create($value->persistenceAttributes());
        }

        return new FormSubmissionResult(
            submissionId: (int) $submission->getKey(),
            definitionId: $form->definitionId,
            versionId: $form->versionId,
            versionNumber: $form->versionNumber,
            formKey: $form->key,
            contactId: $contact !== null ? (int) $contact->getKey() : null,
            status: $submission->status,
            submittedAt: $submission->submitted_at?->toAtomString(),
            replayed: false,
        );
    }

    private function replay(
        FormSubmission $submission,
        FormSubmissionInput $input,
        string $fingerprint,
    ): FormSubmissionResult {
        $storedFingerprint = data_get(
            $submission->meta,
            FormSubmissionInput::INTERNAL_META_KEY.'.idempotency_fingerprint',
        );

        if ($submission->trashed()
            || ! is_string($storedFingerprint)
            || ! hash_equals($storedFingerprint, $fingerprint)
        ) {
            throw FormSubmissionReplayConflictException::forIdentity(
                provider: (string) $input->provider,
                externalId: (string) $input->externalId,
            );
        }

        $definition = FormDefinition::withTrashed()->findOrFail(
            $submission->form_definition_id,
        );
        $version = FormVersion::withTrashed()->findOrFail(
            $submission->form_version_id,
        );

        return new FormSubmissionResult(
            submissionId: (int) $submission->getKey(),
            definitionId: (int) $submission->form_definition_id,
            versionId: (int) $submission->form_version_id,
            versionNumber: (int) $version->version,
            formKey: $definition->key,
            contactId: $submission->contact_id !== null
                ? (int) $submission->contact_id
                : null,
            status: $submission->status,
            submittedAt: $submission->submitted_at?->toAtomString(),
            replayed: true,
        );
    }

    private function existingSubmission(
        FormSubmissionInput $input,
        bool $lock = false,
    ): ?FormSubmission {
        $query = FormSubmission::withTrashed()
            ->where('provider', $input->provider)
            ->where('external_id', $input->externalId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function fingerprint(FormSubmissionInput $input): string
    {
        try {
            $encoded = json_encode([
                'form_key' => $input->formKey,
                'source' => $input->source,
                'values' => $this->canonicalize($input->values),
                'meta' => $this->canonicalize($input->meta),
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException(
                'Form submission logical request must be JSON-encodable.',
                previous: $exception,
            );
        }

        return hash('sha256', $encoded);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize($item),
                $value,
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique constraint')
            || str_contains(strtolower($exception->getMessage()), 'duplicate entry');
    }
}