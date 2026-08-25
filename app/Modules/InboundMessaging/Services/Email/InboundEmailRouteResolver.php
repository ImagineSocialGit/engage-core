<?php

namespace App\Modules\InboundMessaging\Services\Email;

use App\Modules\InboundMessaging\Data\Email\ResolvedInboundEmailRoute;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use Illuminate\Support\Facades\Schema;

class InboundEmailRouteResolver
{
    /** @param array<int, string> $toAddresses */
    public function resolve(array $toAddresses): ?ResolvedInboundEmailRoute
    {
        $domain = $this->configuredDomain();

        if ($domain === null || ! Schema::hasTable('inbound_email_routes')) {
            return null;
        }

        foreach ($toAddresses as $address) {
            $normalizedAddress = $this->normalizeAddress($address);

            if ($normalizedAddress === null) {
                continue;
            }

            [$localPart, $addressDomain] = explode('@', $normalizedAddress, 2);

            if ($addressDomain !== $domain) {
                continue;
            }

            $route = InboundEmailRoute::query()
                ->active()
                ->whereRaw('LOWER(local_part) = ?', [$localPart])
                ->first();

            if ($route instanceof InboundEmailRoute) {
                return new ResolvedInboundEmailRoute(
                    route: $route,
                    address: $normalizedAddress,
                );
            }
        }

        return null;
    }

    public function configuredDomain(): ?string
    {
        $domain = trim(mb_strtolower((string) config(
            'messaging.email.inbound_domain',
            '',
        )));

        if ($domain === ''
            || str_contains($domain, '@')
            || filter_var('route@'.$domain, FILTER_VALIDATE_EMAIL) === false
        ) {
            return null;
        }

        return $domain;
    }

    public function normalizeLocalPart(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(mb_strtolower($value));

        if ($value === '' || mb_strlen($value) > 190) {
            return null;
        }

        if (preg_match('/^[a-z0-9][a-z0-9._+\-]*$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function normalizeAddress(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/<([^<>]+)>/', $value, $matches) === 1) {
            $value = trim($matches[1]);
        }

        $value = mb_strtolower($value);

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        [$localPart, $domain] = explode('@', $value, 2);
        $localPart = $this->normalizeLocalPart($localPart);

        if ($localPart === null) {
            return null;
        }

        return $localPart.'@'.$domain;
    }
}