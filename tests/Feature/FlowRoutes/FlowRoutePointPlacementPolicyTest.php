<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Services\FlowRoutePointPlacementPolicy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FlowRoutePointPlacementPolicyTest extends TestCase
{
    public function test_wait_cannot_be_terminal(): void
    {
        $policy = app(FlowRoutePointPlacementPolicy::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Wait can't be the final Point.");

        $policy->assertValidSequence(collect([
            $this->point(FlowRoutePointType::CreateTask->value),
            $this->point(FlowRoutePointType::Wait->value),
        ]));
    }

    public function test_change_status_must_be_terminal(): void
    {
        $policy = app(FlowRoutePointPlacementPolicy::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Change Status must be the final Point in the Route');

        $policy->assertValidSequence(collect([
            $this->point(FlowRoutePointType::ChangeStatus->value),
            $this->point(FlowRoutePointType::CreateTask->value),
        ]));
    }

    public function test_wait_is_valid_in_the_middle_and_change_status_is_valid_at_the_end(): void
    {
        $policy = app(FlowRoutePointPlacementPolicy::class);

        $policy->assertValidSequence(collect([
            $this->point(FlowRoutePointType::CreateTask->value),
            $this->point(FlowRoutePointType::Wait->value),
            $this->point(FlowRoutePointType::SendMessage->value),
            $this->point(FlowRoutePointType::ChangeStatus->value),
        ]));

        $this->addToAssertionCount(1);
    }

    public function test_new_wait_is_inserted_before_current_final_point(): void
    {
        $policy = app(FlowRoutePointPlacementPolicy::class);
        $task = $this->point(FlowRoutePointType::CreateTask->value);
        $message = $this->point(FlowRoutePointType::SendMessage->value);
        $wait = $this->point(FlowRoutePointType::Wait->value);

        $proposed = $policy->proposedAdditionOrder(
            collect([$task, $message]),
            $wait,
        );

        $this->assertSame([$task, $wait, $message], $proposed->all());
        $policy->assertValidSequence($proposed);
    }

    public function test_new_point_is_inserted_before_terminal_change_status(): void
    {
        $policy = app(FlowRoutePointPlacementPolicy::class);
        $task = $this->point(FlowRoutePointType::CreateTask->value);
        $changeStatus = $this->point(FlowRoutePointType::ChangeStatus->value);
        $message = $this->point(FlowRoutePointType::SendMessage->value);

        $proposed = $policy->proposedAdditionOrder(
            collect([$task, $changeStatus]),
            $message,
        );

        $this->assertSame([$task, $message, $changeStatus], $proposed->all());
        $policy->assertValidSequence($proposed);
    }

    private function point(string $type): FlowRoutePoint
    {
        return new FlowRoutePoint([
            'type' => $type,
        ]);
    }
}
