<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\Webinar;

class ResolveWebinarPlaybackUrlAction
{
    public function execute(Webinar $webinar): ?string
    {
        return $webinar->playback_url;
    }
}