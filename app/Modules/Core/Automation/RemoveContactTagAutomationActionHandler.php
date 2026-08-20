<?php

namespace App\Modules\Core\Automation;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;

class RemoveContactTagAutomationActionHandler implements AutomationActionHandler
{
    private const MAX_TAG_LENGTH = 255;

    public function key(): string
    {
        return 'core.remove_contact_tag';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        $contact = $context->model('current_contact');
        $tag = trim((string) ($context->input['tag'] ?? ''));

        if (! $contact instanceof Contact) {
            return AutomationActionResult::failed(
                reason: 'contact_tag_contact_missing',
            );
        }

        if ($tag === '' || mb_strlen($tag) > self::MAX_TAG_LENGTH) {
            return AutomationActionResult::failed(
                reason: 'contact_tag_invalid',
                output: ['tag' => $tag],
            );
        }

        $removed = ContactTag::query()
            ->where('contact_id', $contact->getKey())
            ->where('tag', $tag)
            ->delete();

        return AutomationActionResult::completed(
            reason: $removed > 0
                ? 'contact_tag_removed'
                : 'contact_tag_already_absent',
            output: [
                'contact_id' => $contact->getKey(),
                'tag' => $tag,
                'removed_count' => $removed,
            ],
        );
    }
}