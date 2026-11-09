<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentIntegrityFinding;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class EnvironmentFileInspectionTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryFiles = [];

    public function test_duplicate_root_assignment_blocks_even_when_both_values_match(): void
    {
        $resolver = $this->resolver(
            "APP_ENV=testing\nAPP_ENV=testing\n",
            "APP_URL=https://example.test\n",
        );

        $plan = $resolver->resolve();

        $this->assertFalse($plan->ready());
        $this->assertSame([], $plan->blockingEnvironmentRequirements());
        $this->assertSame([
            ['duplicate_environment_key', 'APP_ENV', 'root', 2],
        ], $this->findings($plan));
    }

    public function test_duplicate_client_secret_is_reported_without_either_value(): void
    {
        $first = 'private-first-token';
        $second = 'private-second-token';
        $resolver = $this->resolver(
            "APP_ENV=testing\n",
            "APP_URL=https://example.test\nDB_PASSWORD={$first}\nDB_PASSWORD={$second}\n",
        );

        $plan = $resolver->resolve();
        $serialized = json_encode($plan->toArray(), JSON_THROW_ON_ERROR);

        $this->assertFalse($plan->ready());
        $this->assertSame([
            ['duplicate_environment_key', 'DB_PASSWORD', 'client', 3],
        ], $this->findings($plan));
        $this->assertStringNotContainsString($first, $serialized);
        $this->assertStringNotContainsString($second, $serialized);
    }

    public function test_malformed_file_is_a_redacted_blocker_instead_of_an_exception(): void
    {
        $secret = 'private-error-fragment';
        $resolver = $this->resolver(
            "APP_ENV=testing\n",
            "APP_URL=\"{$secret}\n",
        );

        $plan = $resolver->resolve();
        $report = $plan->toArray();
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        $this->assertFalse($plan->ready());
        $this->assertContains(
            EnvironmentIntegrityFinding::MALFORMED_FILE,
            array_column($report['environment_integrity_findings'], 'code'),
        );
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertSame('[file]', $report['environment_integrity_findings'][0]['key']);
        $this->assertSame(1, $report['environment_integrity_findings'][0]['line']);
    }

    public function test_unterminated_root_quote_is_a_redacted_blocker(): void
    {
        $secret = 'root-private-error-fragment';
        $plan = $this->resolver(
            "APP_ENV=testing\nAPP_NAME=\"{$secret}\n",
            "APP_URL=https://example.test\n",
        )->resolve();

        $this->assertFalse($plan->ready());
        $this->assertSame([
            ['malformed_environment_file', '[file]', 'root', 2],
        ], $this->findings($plan));
        $this->assertStringNotContainsString(
            $secret,
            json_encode($plan->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_lines_inside_multiline_quoted_values_are_not_considered_assignments(): void
    {
        $resolver = $this->resolver(
            "APP_ENV=testing\n",
            "APP_URL=https://example.test\nFREE_TEXT=\"intro\nAPP_URL=https://not-a-real-assignment.test\noutro\"\n",
        );

        $plan = $resolver->resolve();

        $this->assertTrue($plan->ready());
        $this->assertSame([], $plan->environmentIntegrityFindings);
    }

    public function test_duplicate_unknown_key_still_blocks_but_unique_unknown_key_does_not(): void
    {
        $duplicate = $this->resolver(
            "APP_ENV=testing\nOTHER_SERVICE_SETTING=one\nOTHER_SERVICE_SETTING=two\n",
            "APP_URL=https://example.test\n",
        )->resolve();
        $single = $this->resolver(
            "APP_ENV=testing\nOTHER_SERVICE_SETTING=one\n",
            "APP_URL=https://example.test\n",
        )->resolve();

        $this->assertFalse($duplicate->ready());
        $this->assertSame(
            EnvironmentIntegrityFinding::DUPLICATE_KEY,
            $duplicate->environmentIntegrityFindings[0]->code,
        );
        $this->assertTrue($single->ready());
    }

    public function test_invalid_optional_origin_still_blocks_without_exposing_configured_value(): void
    {
        $value = 'https://example.test/private-path';
        $plan = $this->resolver(
            "APP_ENV=testing\n",
            "APP_URL=https://example.test\nCRM_APP_URL={$value}\n",
            [EnvironmentRequirement::optional('CRM_APP_URL', 'Public origin.', EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN)],
        )->resolve();

        $this->assertFalse($plan->ready());
        $this->assertCount(1, $plan->blockingEnvironmentRequirements());
        $this->assertSame('invalid', $plan->blockingEnvironmentRequirements()[0]->status);
        $this->assertStringNotContainsString($value, json_encode($plan->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_json_command_returns_failure_with_duplicate_finding_without_leaking_values(): void
    {
        $this->app->instance(DeploymentPlanResolver::class, $this->resolver(
            "APP_ENV=testing\nAPP_ENV=production\n",
            "APP_URL=https://example.test\n",
        ));

        $code = Artisan::call('engage:deployment-plan', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $code);
        $this->assertFalse($report['ready']);
        $this->assertSame('duplicate_environment_key', $report['environment_integrity_findings'][0]['code']);
    }

    /**
     * @param array<int, EnvironmentRequirement> $extras
     */
    private function resolver(string $rootContents, string $clientContents, array $extras = []): DeploymentPlanResolver
    {
        $root = $this->temporaryFile($rootContents);
        $client = $this->temporaryFile($clientContents);

        $repository = new class ($root, $client) extends EnvironmentFileRepository
        {
            public function __construct(
                private readonly string $root,
                private readonly string $client,
            ) {}

            public function pathForScope(string $scope): string
            {
                return $scope === 'root' ? $this->root : $this->client;
            }
        };

        $contributor = new class ($extras) implements DeploymentPlanContributor
        {
            /** @param array<int, EnvironmentRequirement> $extras */
            public function __construct(private readonly array $extras) {}

            public function owner(): string
            {
                return 'core';
            }

            public function environmentRequirements(): iterable
            {
                yield EnvironmentRequirement::required('APP_ENV', 'Runtime environment.');
                yield EnvironmentRequirement::required('APP_URL', 'Client origin.');
                yield from $this->extras;
            }
        };

        $modules = new class extends ModuleManager
        {
            public function definitions(): array
            {
                return ['core' => []];
            }

            public function enabledKeys(): array
            {
                return ['core'];
            }
        };

        return new DeploymentPlanResolver([$contributor], $repository, $modules);
    }

    private function temporaryFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'engage-env-inspect-');

        if ($path === false) {
            $this->fail('Unable to create temporary environment fixture.');
        }

        $this->temporaryFiles[] = $path;
        file_put_contents($path, $content);

        return $path;
    }

    /** @return array<int, array{string, string, ?string, ?int}> */
    private function findings($plan): array
    {
        return array_map(
            static fn (EnvironmentIntegrityFinding $finding): array => [
                $finding->code,
                $finding->key,
                $finding->scope,
                $finding->line,
            ],
            $plan->environmentIntegrityFindings,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }
}