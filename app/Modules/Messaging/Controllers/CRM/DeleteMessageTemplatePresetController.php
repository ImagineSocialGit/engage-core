<?php

namespace App\Modules\Messaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Actions\DeleteMessageTemplatePresetAction;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final class DeleteMessageTemplatePresetController extends Controller
{
    public function __invoke(
        MessageTemplatePreset $messageTemplatePreset,
        DeleteMessageTemplatePresetAction $deleteTemplate,
    ): RedirectResponse {
        try {
            $result = $deleteTemplate->handle($messageTemplatePreset);
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('crm.messaging.message-templates.index', [
                    'preset' => $messageTemplatePreset->getKey(),
                ])
                ->withErrors([
                    'message_template' => $exception->getMessage(),
                ]);
        }

        $message = 'Message template deleted from the library. Existing published and scheduled message history was preserved.';

        if (($result['source'] ?? null) === 'config') {
            $message .= ' If its owning module is enabled and synchronized again, its configured default can be recreated.';
        }

        return redirect()
            ->route('crm.messaging.message-templates.index')
            ->with('status', $message);
    }
}