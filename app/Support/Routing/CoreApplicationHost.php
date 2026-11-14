<?php

namespace App\Support\Routing;

final class CoreApplicationHost
{
    /**
     * Select the Core operator host. CRM_APP_URL is an explicit compatibility
     * override; otherwise new clients default to app.<root-domain>.
     */
    public static function resolve(?string $crmUrl, string $rootDomain): string
    {
        $host = parse_url((string) $crmUrl, PHP_URL_HOST);

        return is_string($host) && $host !== ''
            ? $host
            : 'app.'.$rootDomain;
    }
}