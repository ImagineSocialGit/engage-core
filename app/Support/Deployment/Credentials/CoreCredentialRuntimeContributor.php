<?php

namespace App\Support\Deployment\Credentials;

/** Enabled capability owners declare their actual runtime secret consumers. */
interface CoreCredentialRuntimeContributor
{
    public const TAG = 'deployment.credential_runtime_contributors';

    public function owner(): string;

    /** @return iterable<CoreCredentialRuntimeBinding> */
    public function runtimeBindings(): iterable;
}