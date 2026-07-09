<?php

namespace Tests\Feature\SetupValidation;

use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\SetupValidation\SetupValidationManager;
use InvalidArgumentException;
use Tests\TestCase;

class SetupValidationManagerTest extends TestCase
{
    public function test_it_composes_contributors_and_summarizes_errors_and_warnings(): void
    {
        $manager = new SetupValidationManager([
            $this->contributor([
                $this->finding(
                    severity: SetupValidationFinding::SEVERITY_WARNING,
                    code: 'tasks.template.unused',
                    message: 'Task template is valid but unused.',
                    source: 'presets.tasks',
                    path: 'presets.tasks.definitions.follow_up',
                    module: 'tasks',
                ),
            ]),
            $this->contributor([
                $this->finding(
                    severity: SetupValidationFinding::SEVERITY_ERROR,
                    code: 'core.contact_status.missing',
                    message: 'Selected ContactStatus definition is missing.',
                    source: 'presets.contact-statuses',
                    path: 'presets.contact-statuses.definitions.prospect',
                    module: 'core',
                ),
            ]),
        ]);

        $result = $manager->validate();

        $this->assertTrue($result->hasErrors());
        $this->assertSame(1, $result->errorCount());
        $this->assertSame(1, $result->warningCount());
        $this->assertCount(2, $result->findings());

        $this->assertSame(
            'core.contact_status.missing',
            $result->findings()[0]->code,
        );

        $this->assertSame(
            'tasks.template.unused',
            $result->findings()[1]->code,
        );
    }

    public function test_it_orders_findings_deterministically_without_deduplicating_them(): void
    {
        $duplicate = $this->finding(
            severity: SetupValidationFinding::SEVERITY_WARNING,
            code: 'messaging.token.unknown',
            message: 'Token is not declared for this context.',
            source: 'messaging.email.transactional.webinar',
            path: 'payload.body',
            module: 'messaging',
        );

        $manager = new SetupValidationManager([
            $this->contributor([
                $duplicate,
                $this->finding(
                    severity: SetupValidationFinding::SEVERITY_ERROR,
                    code: 'tasks.template.missing',
                    message: 'Referenced TaskTemplate is missing.',
                    source: 'presets.flow-routes',
                    path: 'routes.follow_up.points.0',
                    module: 'flow_routes',
                ),
            ]),
            $this->contributor([
                $duplicate,
                $this->finding(
                    severity: SetupValidationFinding::SEVERITY_ERROR,
                    code: 'campaign.variant.invalid',
                    message: 'Campaign step variant is invalid.',
                    source: 'presets.campaigns',
                    path: 'definitions.nurture.steps.1.variants.email',
                    module: 'campaigns',
                ),
            ]),
        ]);

        $findings = $manager->validate()->findings();

        $this->assertSame([
            'campaign.variant.invalid',
            'tasks.template.missing',
            'messaging.token.unknown',
            'messaging.token.unknown',
        ], array_map(
            fn (SetupValidationFinding $finding): string => $finding->code,
            $findings,
        ));
    }

    public function test_it_rejects_invalid_contributor_values(): void
    {
        $manager = new SetupValidationManager([
            'not-a-contributor',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Setup validation manager received invalid contributor [string].'
        );

        $manager->validate();
    }

    public function test_it_rejects_invalid_findings_returned_by_contributors(): void
    {
        $manager = new SetupValidationManager([
            $this->contributor([
                'not-a-finding',
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('returned invalid finding [string].');

        $manager->validate();
    }

    public function test_finding_validates_severity_and_required_identity_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unsupported setup validation severity [notice].'
        );

        new SetupValidationFinding(
            severity: 'notice',
            code: 'setup.notice',
            message: 'Unsupported severity.',
            source: 'test',
        );
    }

    public function test_result_and_finding_array_shapes_are_stable(): void
    {
        $finding = $this->finding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: 'flow_routes.capability.missing',
            message: 'Selected route capability is missing.',
            source: 'presets.flow-routes',
            path: 'definitions.prospect.points.2.capability_key',
            module: 'flow_routes',
            context: [
                'route_key' => 'prospect',
                'point_key' => 'follow_up',
            ],
            meta: [
                'capability_key' => 'tasks.create_task',
            ],
        );

        $result = (new SetupValidationManager([
            $this->contributor([$finding]),
        ]))->validate();

        $this->assertSame([
            'findings' => [[
                'severity' => 'error',
                'code' => 'flow_routes.capability.missing',
                'message' => 'Selected route capability is missing.',
                'source' => 'presets.flow-routes',
                'path' => 'definitions.prospect.points.2.capability_key',
                'module' => 'flow_routes',
                'context' => [
                    'route_key' => 'prospect',
                    'point_key' => 'follow_up',
                ],
                'meta' => [
                    'capability_key' => 'tasks.create_task',
                ],
            ]],
            'error_count' => 1,
            'warning_count' => 0,
            'has_errors' => true,
        ], $result->toArray());
    }

    /**
     * @param array<int, mixed> $findings
     */
    private function contributor(array $findings): SetupValidationContributor
    {
        return new class ($findings) implements SetupValidationContributor
        {
            /**
             * @param array<int, mixed> $findings
             */
            public function __construct(
                private readonly array $findings,
            ) {}

            public function findings(): iterable
            {
                yield from $this->findings;
            }
        };
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $meta
     */
    private function finding(
        string $severity,
        string $code,
        string $message,
        string $source,
        ?string $path = null,
        ?string $module = null,
        array $context = [],
        array $meta = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: $severity,
            code: $code,
            message: $message,
            source: $source,
            path: $path,
            module: $module,
            context: $context,
            meta: $meta,
        );
    }
}
