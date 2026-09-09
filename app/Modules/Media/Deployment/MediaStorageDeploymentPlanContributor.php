<?php

namespace App\Modules\Media\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Contracts\DeploymentSetupContributor;
use App\Support\Deployment\Data\DeploymentSetupStep;
use App\Support\Deployment\Data\EnvironmentRequirement;

final class MediaStorageDeploymentPlanContributor implements DeploymentPlanContributor, DeploymentSetupContributor
{
    public function owner(): string
    {
        return 'storage';
    }

    /** @return iterable<int, EnvironmentRequirement> */
    public function environmentRequirements(): iterable
    {
        $liveRuntime = in_array(app()->environment(), ['staging', 'production'], true);

        if (! $liveRuntime) {
            foreach ($this->storageKeys() as $key) {
                yield EnvironmentRequirement::optional(
                    $key,
                    'Media storage is non-live in local/testing. Configure this value only when exercising a real object-storage backend.',
                    valueRule: in_array($key, ['DO_SPACES_ENDPOINT', 'CDN_BASE_URL'], true)
                        ? EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN
                        : null,
                );
            }

            return;
        }

        yield EnvironmentRequirement::required(
            'FILESYSTEM_DISK',
            'Media is enabled in a live runtime and currently requires the configured writable public object-storage backend.',
            expectedValue: 'spaces',
            allowedValues: ['spaces'],
        );

        yield EnvironmentRequirement::required(
            'DO_SPACES_KEY',
            'Media uploads require a DigitalOcean Spaces access key in staging/production.',
        );
        yield EnvironmentRequirement::required(
            'DO_SPACES_SECRET',
            'Media uploads require the matching DigitalOcean Spaces secret in staging/production.',
        );
        yield EnvironmentRequirement::required(
            'DO_SPACES_ENDPOINT',
            'Media uploads require the root DigitalOcean Spaces endpoint for the selected region.',
            valueRule: EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
        );
        yield EnvironmentRequirement::required(
            'DO_SPACES_REGION',
            'Media uploads require the DigitalOcean Spaces region.',
        );
        yield EnvironmentRequirement::required(
            'DO_SPACES_BUCKET',
            'Media uploads require the selected client DigitalOcean Spaces bucket.',
        );
        yield EnvironmentRequirement::required(
            'CDN_BASE_URL',
            'Media requires a stable public CDN origin for URLs inserted into client communications.',
            valueRule: EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
        );
    }

    /** @return array<int, string> */
    private function storageKeys(): array
    {
        return [
            'FILESYSTEM_DISK',
            'DO_SPACES_KEY',
            'DO_SPACES_SECRET',
            'DO_SPACES_ENDPOINT',
            'DO_SPACES_REGION',
            'DO_SPACES_BUCKET',
            'CDN_BASE_URL',
        ];
    }

    public function setupSteps(): iterable
    {
        if (! in_array(app()->environment(), ['staging', 'production'], true)) {
            return;
        }

        yield new DeploymentSetupStep(
            key: 'storage.digitalocean_spaces',
            title: 'DigitalOcean Spaces storage',
            reason: 'Media is enabled in a live runtime and needs client-scoped writable object storage plus a stable public CDN origin.',
            instructions: [
                'Open DigitalOcean and create or select the client Spaces bucket for this environment.',
                'Enable or confirm the CDN endpoint used for public media URLs.',
                'Create or select access credentials with only the access needed for this client bucket.',
                'Record the region, root Spaces endpoint, bucket name, CDN origin, access key, and secret.',
            ],
            environmentKeys: [
                'DO_SPACES_KEY',
                'DO_SPACES_SECRET',
                'DO_SPACES_ENDPOINT',
                'DO_SPACES_REGION',
                'DO_SPACES_BUCKET',
                'CDN_BASE_URL',
            ],
            verification: [
                'Run php artisan setup:validate and confirm Media storage is clean.',
                'Upload one staging-safe image and verify the generated public URL uses the configured CDN origin.',
            ],
            priority: 30,
        );
    }
}