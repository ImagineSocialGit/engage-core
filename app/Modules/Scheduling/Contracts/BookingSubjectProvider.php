<?php

namespace App\Modules\Scheduling\Contracts;

use Illuminate\Database\Eloquent\Model;

interface BookingSubjectProvider
{
    public function key(): string;

    public function label(): string;

    public function accepts(Model $subject): bool;

    public function allowsSnapshotOnly(): bool;
}