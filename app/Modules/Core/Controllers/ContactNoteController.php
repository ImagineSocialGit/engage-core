<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Requests\StoreContactNoteRequest;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\Note;
use Illuminate\Http\RedirectResponse;

class ContactNoteController extends Controller
{
    public function store(StoreContactNoteRequest $request, Contact $contact)
    {
        $contact->notes()->create($request->validated());

        return redirect()->back();
    }

    public function update(
        StoreContactNoteRequest $request,
        Contact $contact,
        Note $note
    ): RedirectResponse {
        abort_unless(
            $note->contact_id === $contact->getKey(),
            404
        );

        $note->update([
            'content' => $request->validated('content'),
        ]);

        return back()->with(
            'success',
            'Note updated.'
        );
    }

    public function destroy(
        Contact $contact,
        Note $note
    ): RedirectResponse {
        abort_unless(
            $note->contact_id === $contact->getKey(),
            404
        );

        $note->delete();

        return back()->with(
            'success',
            'Note deleted.'
        );
    }

}
