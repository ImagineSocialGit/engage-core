<?php

namespace App\Modules\Messaging\Contracts;

/** Marker for message contexts whose original requested time must survive pacing. */
interface RecordsOriginalRequestedSendAt
{
}