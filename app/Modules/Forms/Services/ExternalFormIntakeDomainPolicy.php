<?php

namespace App\Modules\Forms\Services;

use InvalidArgumentException;

final class ExternalFormIntakeDomainPolicy
{
    private const DOMAIN_PATTERN = '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D';

    /**
     * @return array<int, string>
     */
    public function normalize(mixed $domains, string $clientId): array
    {
        if ($domains === null) {
            return [];
        }

        if (! is_array($domains) || ! array_is_list($domains)) {
            throw new InvalidArgumentException(
                "External Forms intake client [{$clientId}] domains must be a list.",
            );
        }

        $normalized = [];

        foreach ($domains as $domain) {
            if (! is_string($domain)) {
                throw new InvalidArgumentException(
                    "External Forms intake client [{$clientId}] domains must contain only domain strings.",
                );
            }

            $domain = strtolower(rtrim(trim($domain), '.'));

            if (preg_match(self::DOMAIN_PATTERN, $domain) !== 1
                || filter_var($domain, FILTER_VALIDATE_IP) !== false
            ) {
                throw new InvalidArgumentException(
                    "External Forms intake client [{$clientId}] domain [{$domain}] must be a bare public domain without a scheme, path, port, wildcard, or IP address.",
                );
            }

            $normalized[] = $domain;
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            throw new InvalidArgumentException(
                "External Forms intake client [{$clientId}] domains cannot contain duplicates.",
            );
        }

        return $normalized;
    }
}