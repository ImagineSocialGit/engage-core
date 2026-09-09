<?php

namespace App\Support\HumanVerification;

use App\Support\HumanVerification\Contracts\HumanVerificationProvider;
use App\Support\HumanVerification\Data\HumanVerificationRequest;
use App\Support\HumanVerification\Data\HumanVerificationResult;
use App\Support\HumanVerification\Data\HumanVerificationWidget;
use App\Support\HumanVerification\Exceptions\HumanVerificationConfigurationException;
use Illuminate\Contracts\Container\Container;
use Throwable;

final class HumanVerificationManager
{
    private const SURFACE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/D';
    private const ACTION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/D';

    public function __construct(
        private readonly Container $container,
    ) {}

    public function enabledForSurface(string $surface): bool
    {
        return (bool) config('human_verification.enabled', false)
            && (bool) config("human_verification.surfaces.{$surface}.enabled", false);
    }

    public function responseField(): string
    {
        $field = trim((string) config(
            'human_verification.response_field',
            'cf-turnstile-response',
        ));

        return $field !== '' ? $field : 'cf-turnstile-response';
    }

    public function grantTtlSeconds(): int
    {
        return (int) config('human_verification.grant_ttl_seconds', 1800);
    }

    public function validateConfiguration(): void
    {
        if (! (bool) config('human_verification.enabled', false)) {
            return;
        }

        $ttl = $this->grantTtlSeconds();

        if ($ttl < 60 || $ttl > 7200) {
            throw new HumanVerificationConfigurationException(
                'Public human-verification grant TTL must be between 60 and 7200 seconds.',
            );
        }

        foreach ((array) config('human_verification.surfaces', []) as $surface => $definition) {
            if (! is_string($surface)
                || preg_match(self::SURFACE_PATTERN, $surface) !== 1
                || ! is_array($definition)
            ) {
                throw new HumanVerificationConfigurationException(
                    'Public human-verification surfaces must use lowercase identifiers and array definitions.',
                );
            }

            if (! (bool) ($definition['enabled'] ?? false)) {
                continue;
            }

            $action = trim((string) ($definition['action'] ?? ''));

            if ($action === '' || preg_match(self::ACTION_PATTERN, $action) !== 1) {
                throw new HumanVerificationConfigurationException(
                    "Public human-verification action for surface [{$surface}] is invalid.",
                );
            }
        }

        [$provider, $configuration] = $this->resolvedProvider();
        $provider->validateConfiguration($configuration);
    }

    public function widgetForSurface(string $surface): ?HumanVerificationWidget
    {
        if (! $this->enabledForSurface($surface)) {
            return null;
        }

        try {
            [$provider, $configuration] = $this->resolvedProvider();
            $action = $this->actionForSurface($surface);
            $provider->validateConfiguration($configuration);

            return $provider->widget(
                action: $action,
                configuration: $configuration,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $expectedHostnames */
    public function verify(
        string $surface,
        ?string $token,
        array $expectedHostnames,
        ?string $remoteIp = null,
        ?string $expectedAction = null,
    ): HumanVerificationResult {
        if (! $this->enabledForSurface($surface)) {
            return HumanVerificationResult::notRequired();
        }

        try {
            [$provider, $configuration] = $this->resolvedProvider();
            $provider->validateConfiguration($configuration);

            return $provider->verify(
                request: new HumanVerificationRequest(
                    surface: $surface,
                    token: trim((string) $token),
                    expectedAction: $expectedAction ?? $this->actionForSurface($surface),
                    expectedHostnames: array_values(array_unique(array_map(
                        fn (string $hostname): string => $this->normalizeHostname($hostname),
                        $expectedHostnames,
                    ))),
                    remoteIp: $this->normalizeIp($remoteIp),
                ),
                configuration: $configuration,
            );
        } catch (HumanVerificationConfigurationException) {
            return HumanVerificationResult::failed(
                provider: $this->providerKey(),
                reason: HumanVerificationResult::REASON_CONFIGURATION,
            );
        }
    }

    private function actionForSurface(string $surface): string
    {
        if (preg_match(self::SURFACE_PATTERN, $surface) !== 1) {
            throw new HumanVerificationConfigurationException(
                "Public human-verification surface [{$surface}] is invalid.",
            );
        }

        $action = trim((string) config(
            "human_verification.surfaces.{$surface}.action",
            '',
        ));

        if ($action === '' || preg_match(self::ACTION_PATTERN, $action) !== 1) {
            throw new HumanVerificationConfigurationException(
                "Public human-verification action for surface [{$surface}] is invalid.",
            );
        }

        return $action;
    }

    /**
     * @return array{0: HumanVerificationProvider, 1: array<string, mixed>}
     */
    private function resolvedProvider(): array
    {
        $providerKey = $this->providerKey();
        $configuration = config("human_verification.providers.{$providerKey}");

        if (! is_array($configuration)) {
            throw new HumanVerificationConfigurationException(
                "Human-verification provider [{$providerKey}] is not configured.",
            );
        }

        $driver = $configuration['driver'] ?? null;

        if (! is_string($driver) || trim($driver) === '') {
            throw new HumanVerificationConfigurationException(
                "Human-verification provider [{$providerKey}] has no driver.",
            );
        }

        $provider = $this->container->make($driver);

        if (! $provider instanceof HumanVerificationProvider) {
            throw new HumanVerificationConfigurationException(
                "Human-verification driver [{$driver}] must implement ".HumanVerificationProvider::class.'.',
            );
        }

        if ($provider->key() !== $providerKey) {
            throw new HumanVerificationConfigurationException(
                "Human-verification driver [{$driver}] does not match provider key [{$providerKey}].",
            );
        }

        return [$provider, $configuration];
    }

    private function providerKey(): string
    {
        $providerKey = strtolower(trim((string) config(
            'human_verification.provider',
            'turnstile',
        )));

        if ($providerKey === ''
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $providerKey) !== 1
        ) {
            throw new HumanVerificationConfigurationException(
                'Public human-verification provider key is invalid.',
            );
        }

        return $providerKey;
    }

    private function normalizeIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $ip = trim($ip);

        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false
            ? $ip
            : null;
    }

    private function normalizeHostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));

        if ($hostname === ''
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $hostname) !== 1
        ) {
            throw new HumanVerificationConfigurationException(
                "Public human-verification hostname [{$hostname}] is invalid.",
            );
        }

        return $hostname;
    }
}