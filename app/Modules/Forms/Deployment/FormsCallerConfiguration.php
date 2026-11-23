<?php

namespace App\Modules\Forms\Deployment;

use App\Modules\Forms\Services\ExternalFormIntakeDomainPolicy;
use App\Modules\Forms\Services\FormSchemaNormalizer;
use App\Support\Deployment\Credentials\CoreCredentialCallerKey;
use App\Support\Deployment\Credentials\CoreInternalCallerRegistry;
use RuntimeException;

/** Explicit, committed Forms authorization intersected with approved caller metadata. */
final class FormsCallerConfiguration
{
    /** @return array<string, array<string, mixed>> */
    public static function settings(?CoreInternalCallerRegistry $registry, mixed $authorization): array
    {
        if (! is_array($authorization)) {
            throw new RuntimeException('forms_caller_authorization_invalid');
        }
        $result = [];
        foreach (['seo', 'artist'] as $platform) {
            foreach ($registry?->forPurpose('forms.external_intake', $platform) ?? [] as $callerId => $record) {
                $policy = self::authorized($authorization, $platform, $callerId, $record['source_origin']);
                $key = CoreCredentialCallerKey::forCaller('forms.external_intake', $platform, $callerId);
                if ($key === null || isset($result[$callerId])) {
                    throw new RuntimeException('forms_caller_authorization_conflict');
                }
                $result[$callerId] = [
                    'source_platform' => $platform,
                    'source_origin' => $record['source_origin'],
                    'secret_env_key' => $key,
                    'source' => $policy['source'],
                    'provider' => $policy['provider'],
                    'allowed_forms' => $policy['allowed_forms'],
                    'domains' => $policy['domains'],
                ];
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @param mixed $authorization @return array<string, mixed> */
    public static function authorized(mixed $authorization, string $platform, string $callerId, string $origin): array
    {
        $policy = is_array($authorization) ? ($authorization[$callerId] ?? null) : null;
        if (! is_array($policy)
            || array_diff(array_keys($policy), [
                'source_platform', 'source_origin', 'source', 'provider', 'allowed_forms', 'domains',
            ]) !== []
            || ($policy['source_platform'] ?? null) !== $platform
            || ($policy['source_origin'] ?? null) !== $origin
            || ! is_string($policy['source'] ?? null)
            || ! is_string($policy['provider'] ?? null)
            || trim($policy['source']) !== $policy['source']
            || trim($policy['provider']) !== $policy['provider']
            || $policy['source'] === '' || $policy['provider'] === ''
            || strlen($policy['source']) > 255 || strlen($policy['provider']) > 255
        ) {
            throw new RuntimeException('forms_caller_authorization_missing_or_invalid');
        }
        $forms = $policy['allowed_forms'] ?? null;
        if (! is_array($forms) || ! array_is_list($forms) || $forms === []
            || count($forms) !== count(array_unique(array_filter($forms, 'is_string')))
        ) {
            throw new RuntimeException('forms_caller_forms_authorization_invalid');
        }
        foreach ($forms as $form) {
            if (! is_string($form) || preg_match(FormSchemaNormalizer::KEY_PATTERN, $form) !== 1) {
                throw new RuntimeException('forms_caller_forms_authorization_invalid');
            }
        }
        $domains = $policy['domains'] ?? null;
        if (! is_array($domains) || $domains === []) {
            throw new RuntimeException('forms_caller_domain_authorization_missing');
        }
        try {
            (new ExternalFormIntakeDomainPolicy())->normalize($domains, $callerId);
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('forms_caller_domain_authorization_invalid');
        }

        return $policy;
    }
}
