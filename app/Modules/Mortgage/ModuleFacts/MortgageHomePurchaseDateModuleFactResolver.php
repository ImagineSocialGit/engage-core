<?php

namespace App\Modules\Mortgage\ModuleFacts;

use App\Modules\Core\Models\Contact;
use App\Modules\Mortgage\Models\MortgageLoan;
use App\Support\ModuleFacts\Contracts\ModuleFactQueryResolver;
use App\Support\ModuleFacts\Contracts\ModuleFactValueResolver;
use App\Support\ModuleFacts\Data\ModuleFactQuery;
use App\Support\ModuleFacts\Enums\ModuleFactQueryOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class MortgageHomePurchaseDateModuleFactResolver implements ModuleFactValueResolver, ModuleFactQueryResolver
{
    public function resolve(Model $subject): mixed
    {
        if (! $subject instanceof Contact) {
            throw new InvalidArgumentException('The Mortgage home purchase date fact requires a Contact subject.');
        }

        $loan = MortgageLoan::query()
            ->whereNotNull('closed_on')
            ->whereRaw('LOWER(TRIM(loan_purpose)) = ?', ['purchase'])
            ->whereHas('participants', fn (Builder $query) => $query->where('contact_id', $subject->getKey()))
            ->orderByDesc('closed_on')
            ->orderByDesc('id')
            ->first(['closed_on']);

        return $loan?->closed_on;
    }

    public function apply(Builder $query, ModuleFactQuery $factQuery): void
    {
        if ($factQuery->operator !== ModuleFactQueryOperator::AnnualMonthDay
            || ! $factQuery->value instanceof Carbon
        ) {
            throw new InvalidArgumentException('The Mortgage home purchase date fact supports annual month/day queries only.');
        }

        $date = $factQuery->value;
        $month = (int) $date->month;
        $day = (int) $date->day;

        $query->whereExists(function (QueryBuilder $subquery) use ($month, $day, $date): void {
            $subquery->selectRaw('1')
                ->from('mortgage_loan_participants as module_fact_participants')
                ->join(
                    'mortgage_loans as module_fact_loans',
                    'module_fact_loans.id',
                    '=',
                    'module_fact_participants.mortgage_loan_id',
                )
                ->whereColumn('module_fact_participants.contact_id', 'contacts.id')
                ->whereNotNull('module_fact_loans.closed_on')
                ->whereRaw('LOWER(TRIM(module_fact_loans.loan_purpose)) = ?', ['purchase'])
                ->whereRaw(
                    'module_fact_loans.id = (SELECT latest_module_fact_loans.id'
                    .' FROM mortgage_loans AS latest_module_fact_loans'
                    .' INNER JOIN mortgage_loan_participants AS latest_module_fact_participants'
                    .' ON latest_module_fact_participants.mortgage_loan_id = latest_module_fact_loans.id'
                    .' WHERE latest_module_fact_participants.contact_id = contacts.id'
                    .' AND latest_module_fact_loans.closed_on IS NOT NULL'
                    .' AND LOWER(TRIM(latest_module_fact_loans.loan_purpose)) = ?'
                    .' ORDER BY latest_module_fact_loans.closed_on DESC, latest_module_fact_loans.id DESC LIMIT 1)',
                    ['purchase'],
                )
                ->where(function (QueryBuilder $query) use ($month, $day, $date): void {
                    $query->where(function (QueryBuilder $query) use ($month, $day): void {
                        $query->whereMonth('module_fact_loans.closed_on', $month)
                            ->whereDay('module_fact_loans.closed_on', $day);
                    });

                    if ($month === 2 && $day === 28 && ! $date->isLeapYear()) {
                        $query->orWhere(function (QueryBuilder $query): void {
                            $query->whereMonth('module_fact_loans.closed_on', 2)
                                ->whereDay('module_fact_loans.closed_on', 29);
                        });
                    }
                });
        });
    }
}