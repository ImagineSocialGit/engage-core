<?php

namespace App\Modules\Scheduling\Services\BookingSubjects;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Contracts\BookingSubjectProvider;
use App\Modules\Scheduling\Models\BookableService;
use Illuminate\Database\Eloquent\Model;

final class ContactBookingSubjectProvider implements BookingSubjectProvider
{
    public function key(): string
    {
        return BookableService::BOOKING_SUBJECT_CONTACT;
    }

    public function label(): string
    {
        return 'Person';
    }

    public function accepts(Model $subject): bool
    {
        return $subject instanceof Contact;
    }

    public function allowsSnapshotOnly(): bool
    {
        return true;
    }
}