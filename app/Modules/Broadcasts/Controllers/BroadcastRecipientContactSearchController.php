<?php

namespace App\Modules\Broadcasts\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BroadcastRecipientContactSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $search = trim((string) ($validated['q'] ?? ''));

        if ($search === '') {
            return response()->json([
                'contacts' => [],
            ]);
        }

        $contacts = Contact::query()
            ->where(function ($query) use ($search): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('email')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'name', 'email', 'phone'])
            ->map(fn (Contact $contact): array => [
                'id' => $contact->getKey(),
                'label' => $this->contactLabel($contact),
                'name' => $contact->name ?: trim($contact->first_name.' '.$contact->last_name),
                'email' => $contact->email,
                'phone' => $contact->phone,
            ])
            ->values();

        return response()->json([
            'contacts' => $contacts,
        ]);
    }

    private function contactLabel(Contact $contact): string
    {
        $name = $contact->name ?: trim($contact->first_name.' '.$contact->last_name);

        if ($name !== '' && $contact->email) {
            return "{$name} — {$contact->email}";
        }

        if ($name !== '') {
            return $name;
        }

        return $contact->email ?: $contact->phone ?: 'Contact #'.$contact->getKey();
    }
}