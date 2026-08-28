<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Data\DeploymentPlan;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\Data\ResolvedEnvironmentRequirement;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Deployment\EnvironmentFileSynchronizer;
use App\Support\Environment\EnvironmentVariableCatalog;
use Tests\TestCase;

class EnvironmentFileSynchronizerTest extends TestCase
{
    /** @var array<int, string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            $this->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_it_adds_only_missing_required_keys_and_preserves_existing_values(): void
    {
        $directory = $this->temporaryDirectory();
        $rootPath = $directory.'/.env';
        $clientPath = $directory.'/client.env';

        file_put_contents($rootPath, "APP_ENV=local\n");
        file_put_contents($clientPath, "ROOT_DOMAIN=example.test\n");

        $repository = $this->repository($rootPath, $clientPath);
        $synchronizer = new EnvironmentFileSynchronizer($repository);

        $plan = new DeploymentPlan(
            environment: 'local',
            clientKey: 'example-client',
            enabledModules: ['core'],
            environmentRequirements: [
                $this->resolved('APP_ENV', EnvironmentRequirement::REQUIRED, true),
                $this->resolved('APP_KEY', EnvironmentRequirement::REQUIRED, false),
                $this->resolved('ROOT_DOMAIN', EnvironmentRequirement::REQUIRED, true),
                $this->resolved('DB_PASSWORD', EnvironmentRequirement::REQUIRED, false),
                $this->resolved('SESSION_DOMAIN', EnvironmentRequirement::OPTIONAL, false),
            ],
            unusedEnvironmentKeys: [],
            coveredOwners: ['core'],
        );

        $written = $synchronizer->writeMissingRequiredKeys($plan);

        $this->assertEqualsCanonicalizing([
            ['key' => 'APP_KEY', 'path' => $rootPath],
            ['key' => 'DB_PASSWORD', 'path' => $clientPath],
        ], $written);

        $this->assertStringContainsString("APP_ENV=local\n", file_get_contents($rootPath));
        $this->assertStringContainsString("APP_KEY=\n", file_get_contents($rootPath));
        $this->assertStringContainsString("ROOT_DOMAIN=example.test\n", file_get_contents($clientPath));
        $this->assertStringContainsString("DB_PASSWORD=\n", file_get_contents($clientPath));
        $this->assertStringNotContainsString('SESSION_DOMAIN=', file_get_contents($clientPath));
    }


    public function test_it_writes_known_non_secret_expected_values_but_keeps_secrets_blank(): void
    {
        $directory = $this->temporaryDirectory();
        $rootPath = $directory.'/.env';
        $clientPath = $directory.'/client.env';

        $repository = $this->repository($rootPath, $clientPath);
        $plan = new DeploymentPlan(
            environment: 'local',
            clientKey: 'example-client',
            enabledModules: ['core'],
            environmentRequirements: [
                $this->resolved(
                    'CLIENT_KEY',
                    EnvironmentRequirement::REQUIRED,
                    false,
                    expectedValue: 'example-client',
                ),
                $this->resolved('APP_KEY', EnvironmentRequirement::REQUIRED, false),
            ],
            unusedEnvironmentKeys: [],
            coveredOwners: ['core'],
        );

        (new EnvironmentFileSynchronizer($repository))
            ->writeMissingRequiredKeys($plan);

        $contents = file_get_contents($rootPath);

        $this->assertStringContainsString("CLIENT_KEY=example-client
", $contents);
        $this->assertStringContainsString("APP_KEY=
", $contents);
    }

    public function test_it_never_overwrites_an_existing_blank_secret_placeholder(): void
    {
        $directory = $this->temporaryDirectory();
        $rootPath = $directory.'/.env';
        $clientPath = $directory.'/client.env';

        file_put_contents($rootPath, "APP_KEY=\n");
        file_put_contents($clientPath, '');

        $repository = $this->repository($rootPath, $clientPath);
        $plan = new DeploymentPlan(
            environment: 'local',
            clientKey: 'example-client',
            enabledModules: ['core'],
            environmentRequirements: [
                $this->resolved('APP_KEY', EnvironmentRequirement::REQUIRED, true),
            ],
            unusedEnvironmentKeys: [],
            coveredOwners: ['core'],
        );

        $written = (new EnvironmentFileSynchronizer($repository))
            ->writeMissingRequiredKeys($plan);

        $this->assertSame([], $written);
        $this->assertSame("APP_KEY=\n", file_get_contents($rootPath));
    }

    private function resolved(
        string $key,
        string $requirement,
        bool $persisted,
        ?string $expectedValue = null,
    ): ResolvedEnvironmentRequirement {
        return new ResolvedEnvironmentRequirement(
            definition: EnvironmentVariableCatalog::definition($key),
            requirement: new EnvironmentRequirement(
                $key,
                $requirement,
                'Test requirement.',
                $expectedValue,
            ),
            owner: EnvironmentVariableCatalog::definition($key)->owner,
            status: $persisted
                ? ResolvedEnvironmentRequirement::STATUS_READY
                : ResolvedEnvironmentRequirement::STATUS_MISSING,
            targetPath: 'test',
            persisted: $persisted,
        );
    }

    private function repository(string $rootPath, string $clientPath): EnvironmentFileRepository
    {
        return new class ($rootPath, $clientPath) extends EnvironmentFileRepository
        {
            public function __construct(
                private readonly string $rootPath,
                private readonly string $clientPath,
            ) {}

            public function pathForScope(string $scope): string
            {
                return $scope === 'root' ? $this->rootPath : $this->clientPath;
            }
        };
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/engage-env-sync-'.bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);
        $this->directories[] = $directory;

        return $directory;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}