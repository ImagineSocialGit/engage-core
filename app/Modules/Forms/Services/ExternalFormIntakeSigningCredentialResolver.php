<?php

namespace App\Modules\Forms\Services;

use App\Support\Deployment\Credentials\CoreCredentialCallerKey;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Throwable;

/** Same selected-client secret resolver used by HTTP authentication and runtime proof. */
final class ExternalFormIntakeSigningCredentialResolver
{
    public function __construct(private readonly EnvironmentFileRepository $files = new EnvironmentFileRepository()) {}

    /** @param mixed $settings */
    public function hasValidBinding(string $callerId, mixed $settings): bool
    {
        if (! is_array($settings) || array_key_exists('secret', $settings)
            || ! in_array($settings['source_platform'] ?? null, ['seo', 'artist'], true)
        ) {
            return false;
        }
        $expected = CoreCredentialCallerKey::forCaller(
            'forms.external_intake', $settings['source_platform'], $callerId,
        );

        return $expected !== null && ($settings['secret_env_key'] ?? null) === $expected;
    }

    /** @param mixed $settings */
    public function resolve(string $callerId, mixed $settings): ?string
    {
        if (! $this->hasValidBinding($callerId, $settings)) {
            return null;
        }
        try {
            $scope = $this->files->inspectScope(EnvironmentVariableDefinition::SCOPE_CLIENT);
            $root = $this->files->inspectScope(EnvironmentVariableDefinition::SCOPE_ROOT);
        } catch (Throwable) {
            return null;
        }
        if (($scope['issues'] ?? null) !== [] || ! is_array($scope['values'] ?? null)
            || ($root['issues'] ?? null) !== [] || ! is_array($root['values'] ?? null)
            || array_key_exists($settings['secret_env_key'], $root['values'])
        ) {
            return null;
        }
        $value = $scope['values'][$settings['secret_env_key']] ?? null;

        return is_string($value) && preg_match('/\A[A-Za-z0-9_-]{64}\z/D', $value) === 1
            ? $value : null;
    }
}
