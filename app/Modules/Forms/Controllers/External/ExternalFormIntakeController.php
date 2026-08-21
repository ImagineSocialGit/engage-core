<?php

namespace App\Modules\Forms\Controllers\External;

use App\Modules\Forms\Actions\CreateFormSubmissionAction;
use App\Modules\Forms\Data\ExternalFormIntakeClient;
use App\Modules\Forms\Data\FormSubmissionInput;
use App\Modules\Forms\Data\FormSubmissionVerification;
use App\Modules\Forms\Exceptions\FormSubmissionReplayConflictException;
use App\Modules\Forms\Exceptions\FormSubmissionValidationException;
use App\Modules\Forms\Http\Middleware\AuthenticateExternalFormIntake;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class ExternalFormIntakeController
{
    public function __construct(
        private readonly CreateFormSubmissionAction $submissions,
    ) {}

    public function __invoke(Request $request, string $form): JsonResponse
    {
        $decoded = $this->decode($request);

        if ($decoded instanceof JsonResponse) {
            return $decoded;
        }

        [$payload, $document] = $decoded;
        $errors = [];
        $unknownKeys = array_values(array_diff(
            array_keys($payload),
            ['external_id', 'values', 'meta', 'provenance', 'verification'],
        ));

        if ($unknownKeys !== []) {
            $errors['_request'][] = sprintf(
                'Unknown request key(s): %s.',
                implode(', ', $unknownKeys),
            );
        }

        if (property_exists($document, 'values') && ! $document->values instanceof stdClass) {
            $errors['values'][] = 'The values field must be a JSON object.';
        }

        if (property_exists($document, 'meta') && ! $document->meta instanceof stdClass) {
            $errors['meta'][] = 'The meta field must be a JSON object.';
        }

        if (property_exists($document, 'provenance') && ! $document->provenance instanceof stdClass) {
            $errors['provenance'][] = 'The provenance field must be a JSON object.';
        }

        if (property_exists($document, 'verification') && ! $document->verification instanceof stdClass) {
            $errors['verification'][] = 'The verification field must be a JSON object.';
        }

        $provenance = is_array($payload['provenance'] ?? null)
            ? $payload['provenance']
            : [];
        $unknownProvenanceKeys = array_values(array_diff(
            array_keys($provenance),
            ['ip_address', 'user_agent'],
        ));

        if ($unknownProvenanceKeys !== []) {
            $errors['provenance'][] = sprintf(
                'Unknown provenance key(s): %s.',
                implode(', ', $unknownProvenanceKeys),
            );
        }

        $verification = is_array($payload['verification'] ?? null)
            ? $payload['verification']
            : [];
        $unknownVerificationKeys = array_values(array_diff(
            array_keys($verification),
            [
                'provider',
                'outcome',
                'verified_at',
                'hostname',
                'action',
            ],
        ));

        if ($unknownVerificationKeys !== []) {
            $errors['verification'][] = sprintf(
                'Unknown verification key(s): %s.',
                implode(', ', $unknownVerificationKeys),
            );
        }

        $validator = Validator::make($payload, [
            'external_id' => ['required', 'string', 'uuid', 'max:255'],
            'values' => ['required', 'array'],
            'meta' => ['sometimes', 'array'],
            'provenance' => ['sometimes', 'array'],
            'provenance.ip_address' => ['sometimes', 'nullable', 'string', 'ip', 'max:45'],
            'provenance.user_agent' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'verification' => ['sometimes', 'array'],
            'verification.provider' => ['required_with:verification', 'string', 'max:64'],
            'verification.outcome' => ['required_with:verification', 'string', 'max:32'],
            'verification.verified_at' => ['required_with:verification', 'string', 'max:64'],
            'verification.hostname' => ['sometimes', 'nullable', 'string', 'max:253'],
            'verification.action' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        foreach ($validator->errors()->toArray() as $key => $messages) {
            $errors[$key] = array_values(array_unique([
                ...($errors[$key] ?? []),
                ...$messages,
            ]));
        }

        if ($errors !== []) {
            return $this->validationFailed($request, $errors);
        }

        $client = $request->attributes->get(
            AuthenticateExternalFormIntake::CLIENT_ATTRIBUTE,
        );

        if (! $client instanceof ExternalFormIntakeClient) {
            Log::error('Authenticated external Forms intake client is missing from the request.');

            return $this->error(
                request: $request,
                status: 503,
                code: 'external_intake_unavailable',
                message: 'External form intake is temporarily unavailable.',
            );
        }

        try {
            $verificationEvidence = array_key_exists('verification', $payload)
                ? new FormSubmissionVerification(
                    provider: (string) ($payload['verification']['provider'] ?? ''),
                    outcome: (string) ($payload['verification']['outcome'] ?? ''),
                    verifiedAt: (string) ($payload['verification']['verified_at'] ?? ''),
                    hostname: isset($payload['verification']['hostname'])
                        ? (string) $payload['verification']['hostname']
                        : null,
                    action: isset($payload['verification']['action'])
                        ? (string) $payload['verification']['action']
                        : null,
                    authenticatedClientId: $client->id,
                )
                : null;
        } catch (InvalidArgumentException $exception) {
            return $this->validationFailed($request, [
                'verification' => [$exception->getMessage()],
            ]);
        }

        try {
            $result = $this->submissions->handle(new FormSubmissionInput(
                formKey: $form,
                values: $payload['values'],
                source: $client->source,
                provider: $client->provider,
                externalId: strtolower($payload['external_id']),
                rawPayload: $payload['values'],
                meta: $payload['meta'] ?? [],
                ipAddress: $payload['provenance']['ip_address'] ?? null,
                userAgent: $payload['provenance']['user_agent'] ?? null,
                verification: $verificationEvidence,
                publicOnly: true,
            ));
        } catch (FormSubmissionValidationException $exception) {
            return $this->validationFailed($request, $exception->errors());
        } catch (FormSubmissionReplayConflictException) {
            return $this->error(
                request: $request,
                status: 409,
                code: 'external_id_conflict',
                message: 'The external submission ID is already associated with a different logical request.',
            );
        } catch (InvalidArgumentException $exception) {
            return $this->validationFailed($request, [
                '_request' => [$exception->getMessage()],
            ]);
        } catch (DomainException $exception) {
            Log::warning('External Forms intake could not resolve a usable published form.', [
                'form_key' => $form,
                'client_id' => $client->id,
                'exception' => $exception,
            ]);

            return $this->error(
                request: $request,
                status: 503,
                code: 'form_unavailable',
                message: 'The requested form is temporarily unavailable.',
            );
        }

        return response()->json([
            'data' => [
                'submission_id' => $result->submissionId,
                'form_key' => $result->formKey,
                'form_version' => [
                    'id' => $result->versionId,
                    'number' => $result->versionNumber,
                ],
                'contact_id' => $result->contactId,
                'status' => $result->status,
                'submitted_at' => $result->submittedAt,
                'replayed' => $result->replayed,
            ],
            'request_id' => $request->attributes->get('request_id'),
        ], $result->replayed ? 200 : 201);
    }

    /**
     * @return array{array<string, mixed>, stdClass}|JsonResponse
     */
    private function decode(Request $request): array|JsonResponse
    {
        try {
            $document = json_decode(
                $request->getContent(),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
            $payload = json_decode(
                $request->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return $this->error(
                request: $request,
                status: 400,
                code: 'invalid_json',
                message: 'The external form intake payload must contain valid JSON.',
            );
        }

        if (! $document instanceof stdClass || ! is_array($payload)) {
            return $this->error(
                request: $request,
                status: 400,
                code: 'invalid_json_document',
                message: 'The external form intake payload must be a JSON object.',
            );
        }

        return [$payload, $document];
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function validationFailed(Request $request, array $errors): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The external form intake payload failed validation.',
                'details' => [
                    'errors' => $errors,
                ],
            ],
            'request_id' => $request->attributes->get('request_id'),
        ], 422);
    }

    private function error(
        Request $request,
        int $status,
        string $code,
        string $message,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'request_id' => $request->attributes->get('request_id'),
        ], $status);
    }
}