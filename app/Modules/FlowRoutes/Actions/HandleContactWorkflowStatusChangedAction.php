<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use Illuminate\Support\Facades\DB;

class HandleContactWorkflowStatusChangedAction
{
    public function __construct(
        private readonly CancelActiveFlowRouteProgressAction $cancelActiveFlowRouteProgress,
        private readonly StartFlowRouteProgressAction $startFlowRouteProgress,
    ) {}

    public function handle(ContactWorkflowStatusTransition $transition): ?ContactFlowRouteProgress
    {
        if (! $transition->changed()) {
            return null;
        }

        return DB::transaction(function () use ($transition) {
            $this->cancelActiveFlowRouteProgress->handle($transition);

            return $this->startFlowRouteProgress->handle($transition);
        });
    }
}