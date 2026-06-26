<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\FlowRoutes\Contracts\FlowRouteConditionEvaluator;
use App\Modules\FlowRoutes\Data\ConditionPointDefinition;
use App\Modules\FlowRoutes\Data\FlowRouteConditionEvaluation;
use App\Modules\FlowRoutes\Data\PointExecutionContext;

class FlowRouteConditionEvaluatorRegistry
{
    /**
     * @param iterable<int, FlowRouteConditionEvaluator> $evaluators
     */
    public function __construct(
        private readonly iterable $evaluators = [],
    ) {}

    /**
     * @param array<int, array<string, mixed>> $conditions
     */
    public function evaluateMany(
        array $conditions,
        PointExecutionContext $context,
        string $mode = ConditionPointDefinition::MODE_ALL,
    ): FlowRouteConditionEvaluation {
        if ($conditions === []) {
            return FlowRouteConditionEvaluation::passed(
                reason: 'no_conditions_defined',
                meta: [
                    'mode' => $mode,
                    'evaluations' => [],
                ],
            );
        }

        $evaluations = [];

        foreach ($conditions as $index => $condition) {
            $evaluation = $this->evaluateOne($condition, $context);

            $evaluations[] = [
                'index' => $index,
                'condition' => $condition,
                'evaluation' => $evaluation->toMetaPayload(),
            ];

            if ($mode === ConditionPointDefinition::MODE_ANY && $evaluation->passed) {
                return FlowRouteConditionEvaluation::passed(
                    reason: 'any_condition_passed',
                    meta: [
                        'mode' => $mode,
                        'evaluations' => $evaluations,
                    ],
                );
            }

            if ($mode !== ConditionPointDefinition::MODE_ANY && ! $evaluation->passed) {
                return FlowRouteConditionEvaluation::failed(
                    reason: 'condition_failed',
                    meta: [
                        'mode' => $mode,
                        'evaluations' => $evaluations,
                    ],
                );
            }
        }

        if ($mode === ConditionPointDefinition::MODE_ANY) {
            return FlowRouteConditionEvaluation::failed(
                reason: 'no_conditions_passed',
                meta: [
                    'mode' => $mode,
                    'evaluations' => $evaluations,
                ],
            );
        }

        return FlowRouteConditionEvaluation::passed(
            reason: 'all_conditions_passed',
            meta: [
                'mode' => $mode,
                'evaluations' => $evaluations,
            ],
        );
    }

    /**
     * @param array<string, mixed> $condition
     */
    public function evaluateOne(
        array $condition,
        PointExecutionContext $context,
    ): FlowRouteConditionEvaluation {
        foreach ($this->evaluators as $evaluator) {
            if (! $evaluator->supports($condition, $context)) {
                continue;
            }

            return $evaluator->evaluate($condition, $context);
        }

        return FlowRouteConditionEvaluation::failed(
            reason: 'condition_evaluator_not_registered',
            meta: [
                'condition' => $condition,
            ],
        );
    }
}