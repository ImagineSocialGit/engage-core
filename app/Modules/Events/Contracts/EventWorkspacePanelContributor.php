<?php

namespace App\Modules\Events\Contracts;

use App\Modules\Events\Models\Event;

interface EventWorkspacePanelContributor
{
    public const TAG = 'events.workspace_panel_contributors';

    /**
     * @return iterable<int, \App\Modules\Events\Data\EventWorkspacePanel>
     */
    public function panels(Event $event): iterable;
}