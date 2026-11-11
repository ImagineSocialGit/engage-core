<?php

namespace App\Support\Deployment\Credentials;

/** Optional extension of an enabled owner's runtime contributor, not authority. */
interface CoreCredentialCallerContributor extends CoreCredentialRuntimeContributor
{
    /** @return iterable<array{purpose:string,source_platform:string}> */
    public function callerCapabilities(): iterable;

    /** Read-only owner validation; throw on conflicting effective configuration. */
    public function validateCaller(string $purpose, string $sourcePlatform, string $sourceClient, string $sourceOrigin): void;
}