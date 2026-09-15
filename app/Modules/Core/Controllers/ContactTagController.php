<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactTagController extends Controller
{
    public function store(Request $request, Contact $contact): RedirectResponse
    {
        $validated = $request->validate([
            'tag' => ['required', 'string', 'max:255'],
        ]);

        $tag = trim($validated['tag']);

        $contactTag = ContactTag::query()->firstOrCreate([
            'contact_id' => $contact->getKey(),
            'tag' => $tag,
        ]);

        return redirect()
            ->route('crm.contacts.show', $contact)
            ->with(
                'success',
                $contactTag->wasRecentlyCreated
                    ? 'Tag added.'
                    : 'That tag is already on this contact.',
            );
    }

    public function update(
        Request $request,
        Contact $contact,
        ContactTag $contactTag,
    ): RedirectResponse {
        $this->assertBelongsToContact($contact, $contactTag);

        $validated = $request->validate([
            'tag' => [
                'required',
                'string',
                'max:255',
                Rule::unique('contact_tags', 'tag')
                    ->where(fn ($query) => $query->where(
                        'contact_id',
                        $contact->getKey(),
                    ))
                    ->ignore($contactTag->getKey()),
            ],
        ]);

        $contactTag->forceFill([
            'tag' => trim($validated['tag']),
        ])->save();

        return redirect()
            ->route('crm.contacts.show', $contact)
            ->with('success', 'Tag updated.');
    }

    public function destroy(
        Contact $contact,
        ContactTag $contactTag,
    ): RedirectResponse {
        $this->assertBelongsToContact($contact, $contactTag);

        $contactTag->delete();

        return redirect()
            ->route('crm.contacts.show', $contact)
            ->with('success', 'Tag removed.');
    }

    private function assertBelongsToContact(
        Contact $contact,
        ContactTag $contactTag,
    ): void {
        abort_unless(
            (int) $contactTag->contact_id === (int) $contact->getKey(),
            404,
        );
    }
}