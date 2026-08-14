<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\ExternalFormIntakeClient;
use InvalidArgumentException;

final class ExternalFormIntakeClientResolver
{
    private const ID_PATTERN = '/^[a-z][a-z0-9_.-]*$/';

    /**
     * @return array<string, ExternalFormIntakeClient>
     */
    public function all(): array
    {
        $configured = config('forms.external_intake.clients', []);

        if (! is_array($configured) || $configured === []) {
            throw new InvalidArgumentException(
                'External Forms intake requires at least one configured client.',
            );
        }

        $clients = [];
        $providers = [];

        foreach ($configured as $clientId => $settings) {
            $client = $this->client($clientId, $settings);

            if (isset($providers[$client->provider])) {
                throw new InvalidArgumentException(sprintf(
                    'External Forms intake clients [%s] and [%s] cannot share provider [%s].',
                    $providers[$client->provider],
                    $client->id,
                    $client->provider,
                ));
            }

            $providers[$client->provider] = $client->id;
            $clients[$client->id] = $client;
        }

        return $clients;
    }

    public function find(string $clientId): ?ExternalFormIntakeClient
    {
        return $this->all()[$clientId] ?? null;
    }

    private function client(mixed $clientId, mixed $settings): ExternalFormIntakeClient
    {
        if (! is_string($clientId)
            || preg_match(self::ID_PATTERN, $clientId) !== 1
            || strlen($clientId) > 128
        ) {
            throw new InvalidArgumentException(
                'External Forms intake client IDs must begin with a lowercase letter and contain only lowercase letters, numbers, dots, underscores, or hyphens.',
            );
        }

        if (! is_array($settings)) {
            throw new InvalidArgumentException(
                "External Forms intake client [{$clientId}] must be an array.",
            );
        }

        $unknown = array_diff(
            array_keys($settings),
            ['secret', 'source', 'provider', 'allowed_forms'],
        );

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'External Forms intake client [%s] contains unsupported setting [%s].',
                $clientId,
                (string) reset($unknown),
            ));
        }

        $secret = $settings['secret'] ?? null;

        if (! is_string($secret) || strlen($secret) < 32 || strlen($secret) > 4096) {
            throw new InvalidArgumentException(
                "External Forms intake client [{$clientId}] secret must contain between 32 and 4096 bytes.",
            );
        }

        $source = $this->identity(
            $settings['source'] ?? null,
            "External Forms intake client [{$clientId}] source",
        );
        $provider = $this->identity(
            $settings['provider'] ?? null,
            "External Forms intake client [{$clientId}] provider",
        );
        $allowedForms = $settings['allowed_forms'] ?? null;

        if (! is_array($allowedForms) || $allowedForms === [] || ! array_is_list($allowedForms)) {
            throw new InvalidArgumentException(
                "External Forms intake client [{$clientId}] allowed_forms must be a non-empty list.",
            );
        }

        $normalizedForms = [];

        foreach ($allowedForms as $formKey) {
            if (! is_string($formKey)
                || preg_match(FormSchemaNormalizer::KEY_PATTERN, $formKey) !== 1
            ) {
                throw new InvalidArgumentException(
                    "External Forms intake client [{$clientId}] allowed_forms contains an invalid form key.",
                );
            }

            $normalizedForms[] = $formKey;
        }

        if (count($normalizedForms) !== count(array_unique($normalizedForms))) {
            throw new InvalidArgumentException(
                "External Forms intake client [{$clientId}] allowed_forms cannot contain duplicates.",
            );
        }

        return new ExternalFormIntakeClient(
            id: $clientId,
            secret: $secret,
            source: $source,
            provider: strtolower($provider),
            allowedForms: $normalizedForms,
        );
    }

    private function identity(mixed $value, string $label): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string.");
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 255) {
            throw new InvalidArgumentException(
                "{$label} must contain between 1 and 255 characters.",
            );
        }

        return $value;
    }
}