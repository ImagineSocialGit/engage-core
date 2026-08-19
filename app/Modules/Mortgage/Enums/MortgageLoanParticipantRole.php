<?php

namespace App\Modules\Mortgage\Enums;

enum MortgageLoanParticipantRole: string
{
    case PrimaryBorrower = 'primary_borrower';
    case CoBorrower = 'co_borrower';
}