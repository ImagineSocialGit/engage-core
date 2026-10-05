<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\FormSubmissionContext;
use App\Modules\Forms\Data\HostedFormContextReference;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use JsonException;

final class HostedFormContextService
{
    public const QUERY_KEY = 'context';

    public const QUERY_REFERENCE = 'context_ref';

    public const POST_FIELD = '__forms_context';

    private const TOKEN_VERSION = 1;

    public function __construct(
        private readonly HostedFormContextRegistry $contexts,
    ) {}

    public function signedUrl(
        string $formKey,
        HostedFormContextReference $reference,
    ): string {
        $formKey = $this->formKey($formKey);

        return URL::signedRoute('forms.public.show', [
            'formSlug' => str_replace('_', '-', $formKey),
            self::QUERY_KEY => $reference->key,
            self::QUERY_REFERENCE => $reference->reference,
        ]);
    }

    public function resolveSignedRequest(Request $request): ?FormSubmissionContext
    {
        $key = $request->query(self::QUERY_KEY);
        $reference = $request->query(self::QUERY_REFERENCE);

        if ($key === null && $reference === null) {
            return null;
        }

        if (! is_string($key)
            || ! is_string($reference)
            || ! URL::hasValidSignature($request)
        ) {
            throw new DomainException('Hosted form context link is invalid.');
        }

        return $this->resolve(new HostedFormContextReference(
            key: $key,
            reference: $reference,
        ));
    }

    public function postToken(
        string $formKey,
        FormSubmissionContext $context,
    ): string {
        $payload = [
            'version' => self::TOKEN_VERSION,
            'form_key' => $this->formKey($formKey),
            'context' => $context->reference->toArray(),
        ];

        try {
            $encoded = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new DomainException(
                'Hosted form context token could not be encoded.',
                previous: $exception,
            );
        }

        return Crypt::encryptString($encoded);
    }

    public function resolvePostedRequest(
        Request $request,
        string $formKey,
    ): ?FormSubmissionContext {
        $token = $request->input(self::POST_FIELD);

        if ($token === null) {
            return null;
        }

        if (! is_string($token) || trim($token) === '') {
            throw new DomainException('Hosted form context token is invalid.');
        }

        try {
            $payload = json_decode(
                Crypt::decryptString($token),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (DecryptException|JsonException) {
            throw new DomainException('Hosted form context token is invalid.');
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== self::TOKEN_VERSION
            || ($payload['form_key'] ?? null) !== $this->formKey($formKey)
            || ! is_array($payload['context'] ?? null)
        ) {
            throw new DomainException('Hosted form context token is invalid.');
        }

        $key = $payload['context']['key'] ?? null;
        $reference = $payload['context']['reference'] ?? null;

        if (! is_string($key) || ! is_string($reference)) {
            throw new DomainException('Hosted form context token is invalid.');
        }

        return $this->resolve(new HostedFormContextReference(
            key: $key,
            reference: $reference,
        ));
    }

    private function resolve(
        HostedFormContextReference $reference,
    ): FormSubmissionContext {
        $context = $this->contexts->resolve($reference);

        if (! $context instanceof FormSubmissionContext) {
            throw new DomainException(
                "Hosted form context [{$reference->key}:{$reference->reference}] is unavailable.",
            );
        }

        return $context;
    }

    private function formKey(string $formKey): string
    {
        $formKey = trim($formKey);

        if (preg_match('/^[a-z][a-z0-9_]*$/D', $formKey) !== 1) {
            throw new InvalidArgumentException(
                'Hosted form context form key must use lowercase snake_case and begin with a letter.',
            );
        }

        return $formKey;
    }
}