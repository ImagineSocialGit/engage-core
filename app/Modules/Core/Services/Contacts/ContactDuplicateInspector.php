<?php

namespace App\Modules\Core\Services\Contacts;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactNameNormalizer;
use App\Modules\Core\Support\Contacts\ContactPhoneNormalizer;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class ContactDuplicateInspector
{
    public function __construct(
        private readonly ContactNameNormalizer $nameNormalizer,
        private readonly ContactPhoneNormalizer $phoneNormalizer,
    ) {}

    /**
     * @return array{
     *     name_group_count: int,
     *     phone_group_count: int,
     *     name_groups: array<int, array<string, mixed>>,
     *     phone_groups: array<int, array<string, mixed>>
     * }
     */
    public function inspect(int $limit = 100): array
    {
        $limit = max(1, min(250, $limit));
        $contacts = Contact::query()
            ->orderBy('id')
            ->get([
                'id',
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
            ]);

        $nameGroups = $contacts
            ->groupBy(fn (Contact $contact): string =>
                $this->nameKey($contact) ?? '__missing__')
            ->reject(fn (Collection $group, string $key): bool =>
                $key === '__missing__' || $group->count() < 2)
            ->map(fn (Collection $group): array => $this->nameGroup($group))
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $phoneGroups = $contacts
            ->groupBy(fn (Contact $contact): string =>
                $this->phoneKey($contact->phone) ?? '__missing__')
            ->reject(fn (Collection $group, string $key): bool =>
                $key === '__missing__' || $group->count() < 2)
            ->map(fn (Collection $group, string $key): array => [
                'phone' => $key,
                'contacts' => $group->values(),
            ])
            ->sortBy('phone', SORT_NATURAL)
            ->values();

        return [
            'name_group_count' => $nameGroups->count(),
            'phone_group_count' => $phoneGroups->count(),
            'name_groups' => $nameGroups->take($limit)->all(),
            'phone_groups' => $phoneGroups->take($limit)->all(),
        ];
    }

    private function nameGroup(Collection $contacts): array
    {
        $emails = $contacts
            ->map(fn (Contact $contact): string => mb_strtolower(trim(
                (string) $contact->email,
            )))
            ->filter()
            ->unique()
            ->values();
        $phones = $contacts
            ->map(fn (Contact $contact): ?string => $this->phoneKey(
                $contact->phone,
            ))
            ->filter()
            ->unique()
            ->values();

        return [
            'label' => $this->displayName($contacts->first()),
            'different_emails' => $emails->count() > 1,
            'different_phones' => $phones->count() > 1,
            'contacts' => $contacts->values(),
        ];
    }

    private function nameKey(Contact $contact): ?string
    {
        $name = trim(implode(' ', array_filter([
            trim((string) $contact->first_name),
            trim((string) $contact->last_name),
        ], static fn (string $value): bool => $value !== '')));

        if ($name === '') {
            $name = trim((string) $contact->name);
        }

        if ($name === '') {
            return null;
        }

        $name = $this->nameNormalizer->normalize($name);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return $name !== ''
            ? mb_strtolower($name, 'UTF-8')
            : null;
    }

    private function displayName(?Contact $contact): string
    {
        if (! $contact instanceof Contact) {
            return 'Unnamed contact';
        }

        $name = trim(implode(' ', array_filter([
            trim((string) $contact->first_name),
            trim((string) $contact->last_name),
        ], static fn (string $value): bool => $value !== '')));

        return $name !== ''
            ? $this->nameNormalizer->normalize($name)
            : ($this->nameNormalizer->normalize((string) $contact->name)
                ?: 'Unnamed contact');
    }

    private function phoneKey(mixed $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        try {
            return $this->phoneNormalizer->normalize($phone);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}