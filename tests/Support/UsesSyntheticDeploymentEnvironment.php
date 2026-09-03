<?php

namespace Tests\Support;

use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;

trait UsesSyntheticDeploymentEnvironment
{
    /**
     * Bind a complete synthetic deployment-file view for installer/refresh tests.
     * The real resolver remains authoritative; only its file repository is faked.
     *
     * @param array<string, string> $rootOverrides
     * @param array<string, string> $clientOverrides
     */
    protected function useSyntheticDeploymentEnvironment(
        array $rootOverrides = [],
        array $clientOverrides = [],
    ): void {
        $clientKey = trim((string) config('client.key', ''));

        if ($clientKey === '') {
            $clientKey = 'test-client';
            config()->set('client.key', $clientKey);
        }

        $root = array_replace([
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'CLIENT_KEY' => $clientKey,
        ], $rootOverrides);

        $client = array_replace([
            'ROOT_DOMAIN' => 'example.test',
            'APP_URL' => 'https://example.test',
            'CRM_APP_URL' => 'https://crm.example.test',
            'DB_DATABASE' => (string) config('database.connections.'.config('database.default').'.database', 'engagecore_test'),
            'DB_USERNAME' => 'engagecore_test',
            'DB_PASSWORD' => 'test-password',
            'CACHE_PREFIX' => 'engagecore_test_cache_',
            'REDIS_PREFIX' => 'engagecore_test_',
            'HORIZON_PREFIX' => 'engagecore_test_horizon:',
            'EMAIL_PROVIDER' => 'resend',
            'SMS_ENABLED' => 'false',
            'SMS_PROVIDER' => 'telnyx',
        ], $clientOverrides);

        $repository = new class ($root, $client) extends EnvironmentFileRepository
        {
            /**
             * @param array<string, string> $root
             * @param array<string, string> $client
             */
            public function __construct(
                private readonly array $root,
                private readonly array $client,
            ) {}

            public function pathForScope(string $scope): string
            {
                return base_path($scope === 'root'
                    ? '.env'
                    : 'client/test-client/.env');
            }

            public function valuesForScope(string $scope): array
            {
                return $scope === 'root'
                    ? $this->root
                    : $this->client;
            }
        };

        $this->app->instance(EnvironmentFileRepository::class, $repository);
        $this->app->forgetInstance(DeploymentPlanResolver::class);
    }
}