<?php

namespace App\Support\SetupValidation\Contributors;

use App\Support\ConfigContracts\ConfigContractRegistry;
use App\Support\ConfigContracts\ConfigContractTargetRegistry;
use App\Support\ConfigContracts\Data\ConfigContractTarget;
use App\Support\ConfigContracts\Data\ConfigContractTargetContext;
use App\Support\ConfigContracts\Data\ConfigContractViolation;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;

final class ConfigContractsSetupValidationContributor implements SetupValidationContributor
{
    public function __construct(
        private readonly ConfigContractRegistry $contracts,
        private readonly ConfigContractTargetRegistry $targets,
    ) {}

    public function findings(): iterable
    {
        foreach ($this->targets->targets(ConfigContractTargetContext::current()) as $target) {
            $contract = $this->contracts->get($target->contractKey);

            foreach ($contract->schema()->validate($target->value, $target->path) as $violation) {
                yield $this->findingFromViolation(
                    target: $target,
                    violation: $violation,
                );
            }
        }
    }

    private function findingFromViolation(
        ConfigContractTarget $target,
        ConfigContractViolation $violation,
    ): SetupValidationFinding {
        $contract = $this->contracts->get($target->contractKey);

        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: 'config_contract.'.$violation->code,
            message: $violation->message,
            source: $target->path,
            path: $violation->path,
            module: $contract->owner(),
            context: array_replace($target->context, [
                'contract_key' => $contract->key(),
                'target_path' => $target->path,
            ]),
            meta: [
                'contract_source_pattern' => $contract->sourcePattern(),
                'violation_code' => $violation->code,
                'violation_meta' => $violation->meta,
            ],
        );
    }
}
