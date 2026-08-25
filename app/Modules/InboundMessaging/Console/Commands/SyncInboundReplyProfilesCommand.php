<?php

namespace App\Modules\InboundMessaging\Console\Commands;

use App\Modules\InboundMessaging\Actions\ReplyProfiles\SyncInboundReplyProfilesAction;
use Illuminate\Console\Command;

class SyncInboundReplyProfilesCommand extends Command
{
    protected $signature = 'inbound-messaging:sync-reply-profiles
        {--force : Overwrite database-customized profiles with client configuration}';

    protected $description = 'Sync configured reply profiles into Inbound Messaging database authority.';

    public function handle(SyncInboundReplyProfilesAction $sync): int
    {
        $result = $sync->handle((bool) $this->option('force'));

        $this->table(['Result', 'Count'], [
            ['Created', $result['created']],
            ['Updated', $result['updated']],
            ['Unchanged', $result['unchanged']],
            ['Customized skipped', $result['customized_skipped']],
            ['Removed skipped', $result['removed_skipped']],
        ]);

        return self::SUCCESS;
    }
}