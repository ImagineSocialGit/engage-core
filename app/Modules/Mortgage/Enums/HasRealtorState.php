<?php

namespace App\Modules\Mortgage\Enums;

enum HasRealtorState: string
{
    case Yes = 'yes';
    case No = 'no';
    case Unknown = 'unknown';
}