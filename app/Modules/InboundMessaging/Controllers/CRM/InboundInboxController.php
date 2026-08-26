<?php

namespace App\Modules\InboundMessaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\Inbox\CreateInboundMessageContactAction;
use App\Modules\InboundMessaging\Actions\Inbox\LinkInboundMessageContactAction;
use App\Modules\InboundMessaging\Actions\Inbox\UpdateInboundMessageInboxStateAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\Inbox\InboundInboxWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class InboundInboxController extends Controller
{
    public function index(
        Request $request,
        InboundInboxWorkspace $workspace,
    ): View {
        return view('crm.inbound-messaging.inbox.index', [
            'workspace' => $workspace->index($request),
        ]);
    }

    public function show(
        Request $request,
        InboundMessage $inboundMessage,
        InboundInboxWorkspace $workspace,
    ): View {
        return view('crm.inbound-messaging.inbox.show', [
            'workspace' => $workspace->detail(
                $request,
                $inboundMessage,
            ),
        ]);
    }

    public function state(
        Request $request,
        InboundMessage $inboundMessage,
        UpdateInboundMessageInboxStateAction $updateState,
    ): RedirectResponse {
        $validated = $request->validate([
            'inbox_status' => [
                'required',
                'string',
                Rule::in(InboundMessage::inboxStatuses()),
            ],
        ]);

        $updateState->handle(
            $inboundMessage,
            (string) $validated['inbox_status'],
        );

        return $this->redirectTo($inboundMessage)
            ->with('status', 'Inbox status updated.');
    }

    public function link(
        Request $request,
        InboundMessage $inboundMessage,
        LinkInboundMessageContactAction $linkContact,
    ): RedirectResponse {
        $validated = $request->validate([
            'contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id'),
            ],
        ]);

        $contact = Contact::query()->findOrFail(
            (int) $validated['contact_id'],
        );

        $linkContact->handle($inboundMessage, $contact);

        return $this->redirectTo($inboundMessage)
            ->with('status', config('contacts.labels.singular').' linked to this message.');
    }

    public function unlink(
        InboundMessage $inboundMessage,
        LinkInboundMessageContactAction $linkContact,
    ): RedirectResponse {
        $linkContact->handle($inboundMessage, null);

        return $this->redirectTo($inboundMessage)
            ->with('status', config('contacts.labels.singular').' link removed.');
    }

    public function createContact(
        Request $request,
        InboundMessage $inboundMessage,
        CreateInboundMessageContactAction $createContact,
    ): RedirectResponse {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
        ]);

        $contact = $createContact->handle(
            message: $inboundMessage,
            email: (string) $validated['email'],
            name: $this->nullableString($validated['name'] ?? null),
            phone: $this->nullableString($validated['phone'] ?? null),
        );

        return $this->redirectTo($inboundMessage)
            ->with(
                'status',
                config('contacts.labels.singular').' linked: '.
                    ($contact->name ?: $contact->email),
            );
    }

    private function redirectTo(
        InboundMessage $message,
    ): RedirectResponse {
        return redirect()->route(
            'crm.inbound-messaging.inbox.show',
            $message,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}