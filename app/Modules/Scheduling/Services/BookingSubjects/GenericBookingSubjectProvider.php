<?php

namespace App\Modules\Scheduling\Services\BookingSubjects;

use App\Modules\Scheduling\Contracts\BookingSubjectProvider;
use App\Modules\Scheduling\Models\BookableService;
use Illuminate\Database\Eloquent\Model;

final class GenericBookingSubjectProvider implements BookingSubjectProvider
{
    public function key(): string
    {
        return BookableService::BOOKING_SUBJECT_GENERIC;
    }

    public function label(): string
    {
        return 'Any attendee';
    }

    public function accepts(Model $subject): bool
    {
        return true;
    }

    public function allowsSnapshotOnly(): bool
    {
        return true;
    }
}