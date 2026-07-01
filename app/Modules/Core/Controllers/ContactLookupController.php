<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer', 'exists:contacts,id'],
        ]);

        $ids = $this->normalizedIds($validated['ids'] ?? []);

        if ($ids !== []) {
            return response()->json([
                'contacts' => $this->contactsByIds($ids),
            ]);
        }

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
            ->map(fn (Contact $contact): array => $this->contactPayload($contact))
            ->values();

        return response()->json([
            'contacts' => $contacts,
        ]);
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    private function normalizedIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $id): int => (int) $id,
            $ids,
        ))));
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function contactsByIds(array $ids): array
    {
        return Contact::query()
            ->whereIn('id', $ids)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('email')
            ->get(['id', 'first_name', 'last_name', 'name', 'email', 'phone'])
            ->map(fn (Contact $contact): array => $this->contactPayload($contact))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function contactPayload(Contact $contact): array
    {
        return [
            'id' => $contact->getKey(),
            'label' => $this->contactLabel($contact),
            'name' => $contact->name ?: trim($contact->first_name.' '.$contact->last_name),
            'email' => $contact->email,
            'phone' => $contact->phone,
        ];
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