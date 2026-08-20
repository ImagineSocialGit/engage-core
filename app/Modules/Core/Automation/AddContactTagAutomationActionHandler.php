<?php

namespace App\Modules\Core\Automation;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;

class AddContactTagAutomationActionHandler implements AutomationActionHandler
{
    private const MAX_TAG_LENGTH = 255;

    public function key(): string
    {
        return 'core.add_contact_tag';
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

        $contactTag = ContactTag::query()->firstOrCreate([
            'contact_id' => $contact->getKey(),
            'tag' => $tag,
        ]);

        return AutomationActionResult::completed(
            reason: $contactTag->wasRecentlyCreated
                ? 'contact_tag_added'
                : 'contact_tag_already_present',
            artifacts: [$contactTag],
            output: [
                'contact_id' => $contact->getKey(),
                'contact_tag_id' => $contactTag->getKey(),
                'tag' => $tag,
                'created' => $contactTag->wasRecentlyCreated,
            ],
        );
    }
}