<?php

namespace App\Modules\Core\ModuleFacts;

use App\Modules\Core\Models\Contact;
use App\Support\ModuleFacts\Contracts\ModuleFactQueryResolver;
use App\Support\ModuleFacts\Contracts\ModuleFactValueResolver;
use App\Support\ModuleFacts\Data\ModuleFactQuery;
use App\Support\ModuleFacts\Enums\ModuleFactQueryOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ContactBirthdayModuleFactResolver implements ModuleFactValueResolver, ModuleFactQueryResolver
{
    public function resolve(Model $subject): mixed
    {
        if (! $subject instanceof Contact) {
            throw new InvalidArgumentException('The Contact birthday fact requires a Contact subject.');
        }

        return $subject->birthday;
    }

    public function apply(Builder $query, ModuleFactQuery $factQuery): void
    {
        if ($factQuery->operator !== ModuleFactQueryOperator::AnnualMonthDay
            || ! $factQuery->value instanceof Carbon
        ) {
            throw new InvalidArgumentException('The Contact birthday fact supports annual month/day queries only.');
        }

        $date = $factQuery->value;
        $month = (int) $date->month;
        $day = (int) $date->day;

        $query->where(function (Builder $query) use ($month, $day, $date): void {
            $query->where(function (Builder $query) use ($month, $day): void {
                $query->whereMonth('contacts.birthday', $month)
                    ->whereDay('contacts.birthday', $day);
            });

            if ($month === 2 && $day === 28 && ! $date->isLeapYear()) {
                $query->orWhere(function (Builder $query): void {
                    $query->whereMonth('contacts.birthday', 2)
                        ->whereDay('contacts.birthday', 29);
                });
            }
        });
    }
}