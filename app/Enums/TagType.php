<?php

namespace App\Enums;

enum TagType: string
{
    case Webinar = 'webinar';
    case Nurture = 'nurture';
    case Status = 'status';
    case Source = 'source';
}