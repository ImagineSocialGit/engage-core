<?php

namespace App\Modules\Core\Actions\Contacts;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactNameNormalizer;
use App\Modules\Core\Support\Contacts\ContactPhoneNormalizer;
use InvalidArgumentException;

final class NormalizeContactsAction
{
    public function __construct(
        private readonly ContactNameNormalizer $nameNormalizer,
        private readonly ContactPhoneNormalizer $phoneNormalizer,
    ) {}

    /**
     * @return array{
     *     contacts_examined: int,
     *     contacts_changed: int,
     *     name_contacts_changed: int,
     *     name_fields_changed: int,
     *     phone_numbers_changed: int,
     *     invalid_phone_numbers: int
     * }
     */
    public function inspect(): array
    {
        return $this->summarize(apply: false);
    }

    /**
     * @return array{
     *     contacts_examined: int,
     *     contacts_changed: int,
     *     name_contacts_changed: int,
     *     name_fields_changed: int,
     *     phone_numbers_changed: int,
     *     invalid_phone_numbers: int
     * }
     */
    public function handle(): array
    {
        return $this->summarize(apply: true);
    }

    /**
     * @return array{
     *     contacts_examined: int,
     *     contacts_changed: int,
     *     name_contacts_changed: int,
     *     name_fields_changed: int,
     *     phone_numbers_changed: int,
     *     invalid_phone_numbers: int
     * }
     */
    private function summarize(bool $apply): array
    {
        $summary = [
            'contacts_examined' => 0,
            'contacts_changed' => 0,
            'name_contacts_changed' => 0,
            'name_fields_changed' => 0,
            'phone_numbers_changed' => 0,
            'invalid_phone_numbers' => 0,
        ];

        Contact::query()
            ->select([
                'id',
                'first_name',
                'last_name',
                'name',
                'phone',
                'updated_at',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($contacts) use (&$summary, $apply): void {
                foreach ($contacts as $contact) {
                    $summary['contacts_examined']++;
                    $changes = [];
                    $nameChanged = false;

                    $normalizedNames = $this->nameNormalizer->normalizeFields([
                        'first_name' => $contact->first_name,
                        'last_name' => $contact->last_name,
                        'name' => $contact->name,
                    ]);

                    foreach (['first_name', 'last_name', 'name'] as $field) {
                        $current = $contact->{$field};
                        $normalized = $normalizedNames[$field] ?? $current;

                        if ($normalized === $current) {
                            continue;
                        }

                        $changes[$field] = $normalized;
                        $summary['name_fields_changed']++;
                        $nameChanged = true;
                    }

                    if ($nameChanged) {
                        $summary['name_contacts_changed']++;
                    }

                    $phone = $contact->phone;

                    if (is_string($phone)) {
                        if (trim($phone) === '') {
                            if ($phone !== '') {
                                $changes['phone'] = null;
                                $summary['phone_numbers_changed']++;
                            }
                        } else {
                            try {
                                $normalizedPhone = $this->phoneNormalizer
                                    ->normalize($phone);

                                if ($normalizedPhone === null) {
                                    $summary['invalid_phone_numbers']++;
                                } elseif ($normalizedPhone !== $phone) {
                                    $changes['phone'] = $normalizedPhone;
                                    $summary['phone_numbers_changed']++;
                                }
                            } catch (InvalidArgumentException) {
                                $summary['invalid_phone_numbers']++;
                            }
                        }
                    }

                    if ($changes === []) {
                        continue;
                    }

                    $summary['contacts_changed']++;

                    if (! $apply) {
                        continue;
                    }

                    $contact->forceFill($changes)->saveQuietly();
                }
            });

        return $summary;
    }
}