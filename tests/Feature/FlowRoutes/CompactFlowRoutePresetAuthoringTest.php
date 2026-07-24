<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Services\FlowRoutePresetDefinitionFactory;
use InvalidArgumentException;
use Tests\TestCase;

class CompactFlowRoutePresetAuthoringTest extends TestCase
{
    public function test_factory_derives_route_identity_graph_capabilities_and_runtime_defaults(): void
    {
        $definition = app(FlowRoutePresetDefinitionFactory::class)->fromArray(
            presetKey: 'test',
            definitionKey: 'webinar_follow_up',
            data: [
                'event_key' => 'webinar.attended',
                'name' => 'Webinar Follow Up',
                'source_version' => 'v1',
                'category' => 'webinar',
                'role' => 'follow_up',
                'points' => [
                    'change_status' => [
                        'type' => 'change_status',
                        'definition' => [
                            'contact_status_key' => 'attended_webinar',
                        ],
                    ],
                    'disabled_placeholder' => [
                        'type' => 'noop',
                        'is_active' => false,
                    ],
                    'enroll_campaign' => [
                        'type' => 'enroll_campaign',
                        'definition' => [
                            'campaign_key' => 'webinar_attended_nurture',
                        ],
                    ],
                ],
            ],
        );

        $this->assertSame('webinar_follow_up', $definition->key);
        $this->assertSame(FlowRoute::TRIGGER_AUTOMATION_EVENT, $definition->triggerType());
        $this->assertSame('webinar.attended', $definition->triggerKey());
        $this->assertSame([
            'category' => 'webinar',
            'default_role' => 'follow_up',
        ], $definition->meta);

        [$changeStatus, $disabled, $enrollCampaign] = $definition->points;

        $this->assertSame('change_status', $changeStatus->key);
        $this->assertSame('flow_routes.change_status', $changeStatus->capabilityKey);
        $this->assertSame(10, $changeStatus->sortOrder);
        $this->assertTrue($changeStatus->isStart);
        $this->assertSame('enroll_campaign', $changeStatus->nextPointKey);
        $this->assertSame('v1', $changeStatus->sourceVersion);
        $this->assertSame('webinar_attended_event', $changeStatus->definition['reason']);
        $this->assertFalse($changeStatus->definition['force']);
        $this->assertSame('skipped', $changeStatus->definition['on_same_status']);
        $this->assertSame([
            'source' => 'flow_route',
            'trigger_type' => 'automation_event',
            'event_key' => 'webinar.attended',
        ], $changeStatus->definition['meta']);

        $this->assertSame(20, $disabled->sortOrder);
        $this->assertFalse($disabled->isActive);
        $this->assertFalse($disabled->isStart);
        $this->assertNull($disabled->nextPointKey);
        $this->assertSame('flow_routes.noop', $disabled->capabilityKey);

        $this->assertSame('campaigns.enroll_contact', $enrollCampaign->capabilityKey);
        $this->assertSame(30, $enrollCampaign->sortOrder);
        $this->assertFalse($enrollCampaign->isStart);
        $this->assertNull($enrollCampaign->nextPointKey);
        $this->assertSame('skipped', $enrollCampaign->definition['on_already_enrolled']);
        $this->assertSame([], $enrollCampaign->definition['payload']);
        $this->assertSame([
            'source' => 'flow_route',
            'reason' => 'webinar_attended_event',
        ], $enrollCampaign->definition['meta']);
        $this->assertSame([
            'source' => 'flow_route',
            'trigger_type' => 'automation_event',
            'event_key' => 'webinar.attended',
        ], $enrollCampaign->definition['start_context']);
        $this->assertSame([], $enrollCampaign->definition['exit_conditions']);
    }

    public function test_route_without_contact_status_or_event_is_manual(): void
    {
        $definition = app(FlowRoutePresetDefinitionFactory::class)->fromArray(
            presetKey: 'test',
            definitionKey: 'manual_route',
            data: [
                'name' => 'Manual Route',
                'points' => [
                    'start' => [
                        'type' => 'noop',
                    ],
                ],
            ],
        );

        $this->assertSame(FlowRoute::TRIGGER_MANUAL, $definition->triggerType());
        $this->assertNull($definition->triggerKey());
        $this->assertTrue($definition->points[0]->isStart);
    }

    public function test_factory_rejects_removed_verbose_route_and_point_fields(): void
    {
        $factory = app(FlowRoutePresetDefinitionFactory::class);

        foreach ([
            ['key' => 'legacy_route'],
            ['trigger' => ['type' => 'manual']],
            ['meta' => []],
        ] as $legacyRouteField) {
            try {
                $factory->fromArray(
                    presetKey: 'test',
                    definitionKey: 'route',
                    data: array_replace([
                        'name' => 'Route',
                        'points' => [
                            'start' => ['type' => 'noop'],
                        ],
                    ], $legacyRouteField),
                );

                $this->fail('Expected removed FlowRoute field to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('removed field', $exception->getMessage());
            }
        }

        foreach ([
            'key',
            'capability_key',
            'sort_order',
            'is_start',
            'next_point_key',
            'settings',
            'source_version',
            'meta',
        ] as $legacyPointField) {
            try {
                $factory->fromArray(
                    presetKey: 'test',
                    definitionKey: 'route',
                    data: [
                        'name' => 'Route',
                        'points' => [
                            'start' => [
                                'type' => 'noop',
                                $legacyPointField => $this->legacyPointValue($legacyPointField),
                            ],
                        ],
                    ],
                );

                $this->fail("Expected removed FlowRoute point field [{$legacyPointField}] to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    "removed field [{$legacyPointField}]",
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_factory_rejects_the_legacy_point_list_shape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('points must be a non-empty keyed map');

        app(FlowRoutePresetDefinitionFactory::class)->fromArray(
            presetKey: 'test',
            definitionKey: 'route',
            data: [
                'name' => 'Route',
                'points' => [
                    [
                        'type' => 'noop',
                    ],
                ],
            ],
        );
    }

    private function legacyPointValue(string $field): mixed
    {
        return match ($field) {
            'key' => 'start',
            'capability_key' => 'flow_routes.noop',
            'sort_order' => 10,
            'is_start' => true,
            'next_point_key', 'source_version' => null,
            default => [],
        };
    }
}