<?php

namespace Tests\Feature\SetupValidation;

use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ValidateSetupCommandTest extends TestCase
{
    public function test_it_returns_success_when_clean(): void
    {
        $this->bindManager([]);

        $this->assertSame(0, Artisan::call('setup:validate'));
        $this->assertStringContainsString(
            'Setup validation passed with no findings.',
            Artisan::output(),
        );
    }

    public function test_it_returns_success_for_warnings_only(): void
    {
        $this->bindManager([
            $this->finding(SetupValidationFinding::SEVERITY_WARNING),
        ]);

        $this->assertSame(0, Artisan::call('setup:validate'));
        $this->assertStringContainsString('1 warning(s)', Artisan::output());
    }

    public function test_it_returns_failure_when_errors_exist(): void
    {
        $this->bindManager([
            $this->finding(SetupValidationFinding::SEVERITY_ERROR),
        ]);

        $this->assertSame(1, Artisan::call('setup:validate'));
        $this->assertStringContainsString('1 error(s)', Artisan::output());
    }


    public function test_it_outputs_location_and_compact_diagnostics_without_unbounded_json(): void
    {
        $this->bindManager([
            new SetupValidationFinding(
                severity: SetupValidationFinding::SEVERITY_WARNING,
                code: 'test.setup.compact_diagnostic',
                message: 'Compact diagnostic test.',
                source: 'test.source',
                path: 'test.path',
                module: 'test_module',
                context: [
                    'route_key' => 'test_route',
                ],
                meta: [
                    'large_value' => str_repeat('x', 1000),
                ],
            ),
        ]);

        $this->assertSame(0, Artisan::call('setup:validate'));

        $output = Artisan::output();

        $this->assertStringContainsString(
            '[test_module | test.source | test.path]',
            $output,
        );
        $this->assertStringContainsString('"route_key":"test_route"', $output);
        $this->assertStringContainsString('...', $output);
        $this->assertStringNotContainsString(str_repeat('x', 1000), $output);
    }

    /**
     * @param array<int, SetupValidationFinding> $findings
     */
    private function bindManager(array $findings): void
    {
        $contributor = new class ($findings) implements SetupValidationContributor
        {
            /**
             * @param array<int, SetupValidationFinding> $findings
             */
            public function __construct(
                private readonly array $findings,
            ) {}

            public function findings(): iterable
            {
                yield from $this->findings;
            }
        };

        $this->app->instance(
            SetupValidationManager::class,
            new SetupValidationManager([$contributor]),
        );
    }

    private function finding(string $severity): SetupValidationFinding
    {
        return new SetupValidationFinding(
            severity: $severity,
            code: 'test.setup.finding',
            message: 'Test setup finding.',
            source: 'test',
            path: 'test.path',
            module: 'test',
            context: ['sample' => true],
        );
    }
}


