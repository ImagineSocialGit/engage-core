<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\FormSubmissionVerification;
use App\Modules\Forms\Data\PublishedForm;
use App\Modules\Forms\Exceptions\FormSubmissionValidationException;
use Carbon\CarbonImmutable;
use DomainException;

final class FormSubmissionVerificationPolicy
{
    private const DEFAULT_MAX_AGE_SECONDS = 600;

    private const MAX_FUTURE_SKEW_SECONDS = 60;

    /**
     * @return array{
     *     required: bool,
     *     providers: array<int, string>,
     *     max_age_seconds: int,
     *     action: string|null,
     *     require_hostname: bool
     * }
     */
    public function validateConfiguration(PublishedForm $form): array
    {
        return $this->policy($form);
    }

    public function validate(
        PublishedForm $form,
        ?FormSubmissionVerification $verification,
    ): void {
        $policy = $this->policy($form);

        if ($verification === null) {
            if ($policy['required']) {
                throw $this->submissionException(
                    'This form requires server-authored human-verification evidence.',
                );
            }

            return;
        }

        if ($policy['providers'] !== []
            && ! in_array($verification->provider, $policy['providers'], true)
        ) {
            throw $this->submissionException(
                "Verification provider [{$verification->provider}] is not accepted for this form.",
            );
        }

        if ($policy['action'] !== null
            && $verification->action !== $policy['action']
        ) {
            throw $this->submissionException(
                'Verification action does not match this form.',
            );
        }

        if ($policy['require_hostname'] && $verification->hostname === null) {
            throw $this->submissionException(
                'Verification hostname evidence is required for this form.',
            );
        }

        $verifiedAt = CarbonImmutable::parse($verification->verifiedAt);
        $now = CarbonImmutable::instance(now());

        if ($verifiedAt->greaterThan(
            $now->addSeconds(self::MAX_FUTURE_SKEW_SECONDS),
        )) {
            throw $this->submissionException(
                'Verification evidence is timestamped too far in the future.',
            );
        }

        if ($verifiedAt->lessThan(
            $now->subSeconds($policy['max_age_seconds']),
        )) {
            throw $this->submissionException(
                'Verification evidence is too old for this form.',
            );
        }
    }

    /**
     * @return array{
     *     required: bool,
     *     providers: array<int, string>,
     *     max_age_seconds: int,
     *     action: string|null,
     *     require_hostname: bool
     * }
     */
    private function policy(PublishedForm $form): array
    {
        $submission = $form->settings['submission'] ?? [];

        if (! is_array($submission)) {
            throw $this->configurationException(
                $form,
                'settings.submission must be an array.',
            );
        }

        $value = $submission['verification'] ?? null;

        if ($value === null) {
            return [
                'required' => false,
                'providers' => [],
                'max_age_seconds' => self::DEFAULT_MAX_AGE_SECONDS,
                'action' => null,
                'require_hostname' => false,
            ];
        }

        if (! is_array($value)) {
            throw $this->configurationException(
                $form,
                'settings.submission.verification must be an array.',
            );
        }

        $unknownKeys = array_diff(
            array_keys($value),
            [
                'required',
                'providers',
                'max_age_seconds',
                'action',
                'require_hostname',
            ],
        );

        if ($unknownKeys !== []) {
            throw $this->configurationException(
                $form,
                'settings.submission.verification contains unknown keys: '
                    .implode(', ', $unknownKeys).'.',
            );
        }

        $required = $value['required'] ?? false;

        if (! is_bool($required)) {
            throw $this->configurationException(
                $form,
                'settings.submission.verification.required must be a boolean.',
            );
        }

        $providers = $this->providers(
            $form,
            $value['providers'] ?? [],
        );

        if ($required && $providers === []) {
            throw $this->configurationException(
                $form,
                'settings.submission.verification.providers must contain at least one provider when verification is required.',
            );
        }

        $maxAge = $value['max_age_seconds']
            ?? self::DEFAULT_MAX_AGE_SECONDS;

        if (! is_int($maxAge) || $maxAge < 30 || $maxAge > 3600) {
            throw $this->configurationException(
                $form,
                'settings.submission.verification.max_age_seconds must be an integer between 30 and 3600.',
            );
        }

        $action = $value['action'] ?? null;

        if ($action !== null) {
            if (! is_string($action)) {
                throw $this->configurationException(
                    $form,
                    'settings.submission.verification.action must be a string or null.',
                );
            }

            $action = trim($action);

            if ($action === ''
                || mb_strlen($action) > 64
                || preg_match(
                    '/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D',
                    $action,
                ) !== 1
            ) {
                throw $this->configurationException(
                    $form,
                    'settings.submission.verification.action must use letters, numbers, dots, underscores, or hyphens.',
                );
            }
        }

        $requireHostname = $value['require_hostname'] ?? false;

        if (! is_bool($requireHostname)) {
            throw $this->configurationException(
                $form,
                'settings.submission.verification.require_hostname must be a boolean.',
            );
        }

        return [
            'required' => $required,
            'providers' => $providers,
            'max_age_seconds' => $maxAge,
            'action' => $action,
            'require_hostname' => $requireHostname,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function providers(
        PublishedForm $form,
        mixed $value,
    ): array {
        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->configurationException(
                $form,
                'settings.submission.verification.providers must be a list.',
            );
        }

        $providers = [];

        foreach ($value as $provider) {
            if (! is_string($provider)) {
                throw $this->configurationException(
                    $form,
                    'settings.submission.verification.providers must contain only strings.',
                );
            }

            $provider = strtolower(trim($provider));

            if ($provider === ''
                || mb_strlen($provider) > 64
                || preg_match('/^[a-z][a-z0-9_.-]*$/D', $provider) !== 1
            ) {
                throw $this->configurationException(
                    $form,
                    'settings.submission.verification.providers contains an invalid provider identifier.',
                );
            }

            $providers[] = $provider;
        }

        return array_values(array_unique($providers));
    }

    private function submissionException(
        string $message,
    ): FormSubmissionValidationException {
        return new FormSubmissionValidationException([
            '_verification' => [$message],
        ]);
    }

    private function configurationException(
        PublishedForm $form,
        string $message,
    ): DomainException {
        return new DomainException(
            "Published form [{$form->key}] has invalid verification policy: {$message}",
        );
    }
}