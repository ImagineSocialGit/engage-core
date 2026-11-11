<?php

namespace Tests\Feature\Deployment;

use App\Console\Commands\InspectCoreCredentialCallerCommand;
use App\Support\Deployment\Credentials\CoreCredentialCallerContributor;
use App\Support\Deployment\Credentials\CoreCredentialRuntimeContributor;
use App\Support\Deployment\Credentials\CoreInternalCallerRegistry;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CoreCredentialCallerCommandTest extends TestCase
{
    public function test_generic_caller_metadata_command_is_registered(): void
    {
        self::assertInstanceOf(InspectCoreCredentialCallerCommand::class,
            Artisan::all()['engage:credential-caller:inspect'] ?? null);
    }

    public function test_registered_owner_can_describe_metadata_for_any_selected_client(): void
    {
        $this->configureRuntime();
        $owner = new class implements CoreCredentialCallerContributor {
            public function owner(): string { return 'core'; }
            public function runtimeBindings(): iterable { return []; }
            public function callerCapabilities(): iterable
            {
                yield ['purpose' => 'fixture.checkout', 'source_platform' => 'seo'];
            }
            public function validateCaller(string $purpose, string $sourcePlatform, string $sourceClient, string $sourceOrigin): void {}
        };
        app()->instance('fixture.caller-owner', $owner);
        app()->tag('fixture.caller-owner', CoreCredentialRuntimeContributor::TAG);
        $code = Artisan::call('engage:credential-caller:inspect', [
            'purpose' => 'fixture.checkout', '--source-platform' => 'seo',
            '--source-client' => 'fixture-seo', '--source-origin' => 'http://site.test',
            '--expected-client' => 'fixture-core', '--expected-environment' => 'local',
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $code);
        self::assertTrue($report['metadata_target_recognized']);
        self::assertFalse($report['writes_allowed']);
        self::assertSame(CoreInternalCallerRegistry::ENVIRONMENT_KEY, $report['environment_key']);
        self::assertSame('core', $report['environment_owner']);
        self::assertFalse($report['environment_secret']);
        $registry = CoreInternalCallerRegistry::decode($report['environment_value'], 'fixture-core', 'local');
        self::assertArrayHasKey('fixture-seo', $registry->forPurpose('fixture.checkout', 'seo'));
    }

    public function test_unregistered_purpose_cannot_claim_a_metadata_target(): void
    {
        $this->configureRuntime();
        $code = Artisan::call('engage:credential-caller:inspect', [
            'purpose' => 'fixture.unregistered', '--source-platform' => 'seo',
            '--source-client' => 'fixture-seo', '--source-origin' => 'http://site.test',
            '--expected-client' => 'fixture-core', '--expected-environment' => 'local',
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $code);
        self::assertFalse($report['metadata_target_recognized']);
        self::assertFalse($report['writes_allowed']);
        self::assertArrayNotHasKey('environment_value', $report);
    }
    private function configureRuntime(): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
        config()->set('client.key', 'fixture-core');
        $files = new class extends EnvironmentFileRepository {
            public function inspectScope(string $scope): array { return ['issues' => [], 'values' => []]; }
            public function pathForScope(string $scope): string
            {
                return $scope === 'client' ? base_path('client/fixture-core/.env') : base_path('.env');
            }
        };
        app()->instance(EnvironmentFileRepository::class, $files);
        app()->forgetInstance(DeploymentPlanResolver::class);
    }

}