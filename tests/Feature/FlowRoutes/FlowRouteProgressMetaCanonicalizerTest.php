<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Services\FlowRouteProgressMetaCanonicalizer;
use InvalidArgumentException;
use Tests\TestCase;

class FlowRouteProgressMetaCanonicalizerTest extends TestCase
{
    public function test_it_persists_only_compact_runtime_coordination(): void
    {
        $canonical = $this->canonicalizer()->forPersistence([
            'started_from_workflow_transition' => [
                'from_contact_status_id' => 10,
                'to_contact_status_id' => 11,
                'reason' => 'manual_update',
                'source' => 'crm',
                'actor_type' => 'user',
                'actor_id' => 12,
                'changed_at' => '2026-07-24T20:00:00+00:00',
                'meta' => [
                    'contact' => [
                        'id' => 100,
                        'email' => 'duplicate@example.test',
                    ],
                ],
            ],
            'started_from_automation_event' => [
                'name' => 'webinar.attended',
                'event_id' => 'event-uuid',
                'contact_id' => 100,
                'subject_type' => 'webinar_registration',
                'subject_id' => 200,
                'occurred_at' => '2026-07-24T20:01:00+00:00',
                'payload' => [
                    'webinar' => [
                        'id' => 300,
                    ],
                ],
                'meta' => [
                    'source_module' => 'webinars',
                ],
            ],
            'waiting' => [
                'flow_route_plan_id' => 20,
                'flow_route_plan_item_id' => 21,
                'flow_route_progress_item_id' => 22,
                'flow_route_point_id' => 23,
                'flow_route_point_key' => 'duplicated-point-key',
                'point_type' => 'event_wait',
                'waiting_at' => '2026-07-24T20:02:00+00:00',
                'resume_at' => '2026-07-25T20:02:00+00:00',
                'expected_event' => 'task.completed',
                'reason' => 'event_wait_point_waiting',
                'definition' => [
                    'event_key' => 'task.completed',
                ],
                'correlation' => [
                    'task.task_template_key' => [
                        'route.first_task',
                        'route.second_task',
                    ],
                    'task.id' => '{subject.id}',
                ],
                'matched_event' => [
                    'name' => 'task.completed',
                    'event_id' => 'matched-event-uuid',
                    'contact_id' => 100,
                    'subject_type' => 'task',
                    'subject_id' => 500,
                    'occurred_at' => '2026-07-24T20:03:00+00:00',
                    'payload' => [
                        'task' => [
                            'id' => 500,
                        ],
                    ],
                ],
                'matched_at' => '2026-07-24T20:03:01+00:00',
            ],
            'immediate_execution_continuation' => [
                'status' => 'scheduled',
                'sequence' => 3,
                'scheduled_at' => '2026-07-24T20:04:00+00:00',
                'flow_route_point_id' => 24,
                'source' => 'automation_event_start',
                'execution_budget' => 25,
                'executions_in_slice' => 25,
                'last_result' => [
                    'status' => 'completed',
                    'meta' => [
                        'contact' => [
                            'id' => 100,
                        ],
                    ],
                ],
            ],
            'last_point_execution' => [
                'result' => [
                    'status' => 'completed',
                ],
            ],
            'point_execution_history' => [
                [
                    'result' => [
                        'status' => 'completed',
                    ],
                ],
            ],
            'advancement_history' => [
                [
                    'from_flow_route_point_id' => 1,
                    'to_flow_route_point_id' => 2,
                ],
            ],
            'resume_attempts' => [
                [
                    'waiting' => [
                        'definition' => [
                            'event_key' => 'task.completed',
                        ],
                    ],
                ],
            ],
            'completed' => [
                'result' => [
                    'status' => 'completed',
                ],
            ],
            'failed' => [
                'result' => [
                    'status' => 'failed',
                ],
            ],
        ]);

        $this->assertEquals([
            'started_from_workflow_transition' => [
                'from_contact_status_id' => 10,
                'to_contact_status_id' => 11,
                'actor_id' => 12,
                'reason' => 'manual_update',
                'source' => 'crm',
                'actor_type' => 'user',
                'changed_at' => '2026-07-24T20:00:00+00:00',
            ],
            'started_from_automation_event' => [
                'name' => 'webinar.attended',
                'event_id' => 'event-uuid',
                'subject_type' => 'webinar_registration',
                'contact_id' => 100,
                'subject_id' => 200,
                'occurred_at' => '2026-07-24T20:01:00+00:00',
            ],
            'waiting' => [
                'flow_route_plan_id' => 20,
                'flow_route_plan_item_id' => 21,
                'flow_route_progress_item_id' => 22,
                'flow_route_point_id' => 23,
                'correlation' => [
                    'task.task_template_key' => [
                        'route.first_task',
                        'route.second_task',
                    ],
                    'task.id' => '{subject.id}',
                ],
                'matched_event' => [
                    'name' => 'task.completed',
                    'event_id' => 'matched-event-uuid',
                    'subject_type' => 'task',
                    'contact_id' => 100,
                    'subject_id' => 500,
                    'occurred_at' => '2026-07-24T20:03:00+00:00',
                ],
            ],
            'immediate_execution_continuation' => [
                'status' => 'scheduled',
                'sequence' => 3,
                'flow_route_point_id' => 24,
                'scheduled_at' => '2026-07-24T20:04:00+00:00',
            ],
        ], $canonical);
    }

    public function test_it_rejects_histories_terminal_result_copies_and_settled_handoffs(): void
    {
        $canonical = $this->canonicalizer()->forPersistence([
            'last_point_execution' => [
                'result' => [
                    'status' => 'completed',
                ],
            ],
            'point_execution_history' => [
                [
                    'result' => [
                        'status' => 'completed',
                    ],
                ],
            ],
            'last_advanced' => [
                'result' => [
                    'status' => 'completed',
                ],
            ],
            'advancement_history' => [],
            'last_resume_attempt' => [],
            'resume_attempts' => [],
            'completed' => [],
            'failed' => [],
            'cancelled' => [],
            'immediate_execution_continuation' => [
                'status' => 'settled',
                'last_result' => [
                    'status' => 'completed',
                ],
            ],
            'immediate_execution_continuation_history' => [],
        ]);

        $this->assertEquals([], $canonical);
    }

    public function test_it_preserves_bounded_failed_continuation_diagnostics(): void
    {
        $canonical = $this->canonicalizer()->forPersistence([
            'immediate_execution_continuation' => [
                'status' => 'failed',
                'sequence' => 4,
                'flow_route_point_id' => 50,
                'scheduled_at' => '2026-07-24T20:05:00+00:00',
                'failed_at' => '2026-07-24T20:05:10+00:00',
                'exception_class' => 'RuntimeException',
                'exception_message' => 'Continuation failed.',
                'last_result' => [
                    'status' => 'completed',
                ],
            ],
        ]);

        $this->assertEquals([
            'immediate_execution_continuation' => [
                'status' => 'failed',
                'sequence' => 4,
                'flow_route_point_id' => 50,
                'scheduled_at' => '2026-07-24T20:05:00+00:00',
                'failed_at' => '2026-07-24T20:05:10+00:00',
                'exception_class' => 'RuntimeException',
                'exception_message' => 'Continuation failed.',
            ],
        ], $canonical);
    }

    public function test_it_rejects_nested_correlation_graphs(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->canonicalizer()->forPersistence([
            'waiting' => [
                'flow_route_point_id' => 10,
                'correlation' => [
                    'task.id' => [
                        'nested' => [
                            'id' => 20,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_it_rejects_oversized_correlation_maps(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->canonicalizer()->forPersistence([
            'waiting' => [
                'flow_route_point_id' => 10,
                'correlation' => array_combine(
                    array_map(
                        fn (int $index): string => 'payload.value_'.$index,
                        range(
                            1,
                            FlowRouteProgressMetaCanonicalizer::MAX_CORRELATION_ITEMS + 1,
                        ),
                    ),
                    array_fill(
                        0,
                        FlowRouteProgressMetaCanonicalizer::MAX_CORRELATION_ITEMS + 1,
                        true,
                    ),
                ),
            ],
        ]);
    }

    public function test_it_rejects_oversized_strings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->canonicalizer()->forPersistence([
            'started_from_workflow_transition' => [
                'source' => str_repeat(
                    'x',
                    FlowRouteProgressMetaCanonicalizer::MAX_STRING_BYTES + 1,
                ),
            ],
        ]);
    }

    public function test_it_rejects_metadata_over_the_encoded_size_budget(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->canonicalizer()->forPersistence([
            'waiting' => [
                'flow_route_point_id' => 10,
                'correlation' => [
                    'payload.first' => str_repeat('a', 1000),
                    'payload.second' => str_repeat('b', 1000),
                    'payload.third' => str_repeat('c', 1000),
                    'payload.fourth' => str_repeat('d', 1000),
                ],
            ],
        ]);
    }

    private function canonicalizer(): FlowRouteProgressMetaCanonicalizer
    {
        return new FlowRouteProgressMetaCanonicalizer();
    }
}