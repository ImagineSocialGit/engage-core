<?php

namespace App\Support\ModuleIntegrations\Messaging\Broadcasts;

use App\Modules\Messaging\Contracts\MessageTemplateDeletionReferenceContributor;
use App\Modules\Messaging\Data\MessageTemplateDeletionReference;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class BroadcastMessageTemplateDeletionReferenceContributor implements MessageTemplateDeletionReferenceContributor
{
    public function references(
        MessageTemplatePreset $preset,
        ?MessageTemplate $template,
    ): iterable {
        if (! $template instanceof MessageTemplate
            || ! Schema::hasTable('broadcasts')
            || ! Schema::hasColumn('broadcasts', 'message_template_id')
        ) {
            return;
        }

        $count = DB::table('broadcasts')
            ->where('message_template_id', $template->getKey())
            ->count();

        if ($count < 1) {
            return;
        }

        yield new MessageTemplateDeletionReference(
            moduleKey: 'broadcasts',
            moduleLabel: 'Broadcasts',
            contextLabel: 'Broadcast message history',
            detail: number_format($count).' '.($count === 1 ? 'broadcast references' : 'broadcasts reference').' this canonical template.',
            url: Route::has('crm.broadcasts.index')
                ? route('crm.broadcasts.index')
                : null,
        );
    }
}