<?php

namespace App\Support\ModuleFacts\Data;

use App\Support\ModuleFacts\Enums\ModuleFactQueryOperator;
use Illuminate\Support\Carbon;

final readonly class ModuleFactQuery
{
    public function __construct(
        public ModuleFactQueryOperator $operator,
        public mixed $value,
    ) {}

    public static function annualMonthDay(Carbon $date): self
    {
        return new self(
            operator: ModuleFactQueryOperator::AnnualMonthDay,
            value: $date->copy()->startOfDay(),
        );
    }
}