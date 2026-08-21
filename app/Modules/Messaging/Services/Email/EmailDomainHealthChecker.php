<?php

namespace App\Modules\Messaging\Services\Email;

class EmailDomainHealthChecker
{
    /**
     * True means the domain currently exposes an SMTP mail route.
     * False means DNS answered successfully but no usable MX/A/AAAA route exists.
     * Null means DNS could not be evaluated reliably and must not be treated as invalid.
     */
    public function hasMailRoute(string $domain): ?bool
    {
        $domain = strtolower(trim($domain));

        if ($domain === '' || ! function_exists('dns_get_record')) {
            return null;
        }

        $mxRecords = @dns_get_record($domain, DNS_MX);

        if ($mxRecords === false) {
            return null;
        }

        foreach ($mxRecords as $record) {
            $target = strtolower(rtrim(trim((string) ($record['target'] ?? '')), '.'));

            if ($target !== '') {
                return true;
            }
        }

        $addressRecords = @dns_get_record($domain, DNS_A | DNS_AAAA);

        if ($addressRecords === false) {
            return null;
        }

        return $addressRecords !== [];
    }
}