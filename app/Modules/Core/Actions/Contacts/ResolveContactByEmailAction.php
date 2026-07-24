<?php

namespace App\Modules\Core\Actions\Contacts;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class ResolveContactByEmailAction
{
    /**
     * @param array<string, mixed> $meta
     */
    public function handle(
        string $email,
        ?string $name = null,
        ?string $phone = null,
        string $source = 'public_booking',
        ?string $subsource = 'scheduling',
        array $meta = [],
    ): Contact {
        $email = $this->normalizedEmail($email);
        $name = $this->nullableString($name, 'name', 255);
        $phone = $this->nullableString($phone, 'phone', 255);
        $source = $this->requiredString($source, 'source', 255);
        $subsource = $this->nullableString($subsource, 'subsource', 255);

        $existing = $this->existingContact($email);

        if ($existing instanceof Contact) {
            return $existing;
        }

        try {
            return Contact::query()->create([
                'name' => $name ?? $email,
                'email' => $email,
                'phone' => $phone,
                'source' => $source,
                'subsource' => $subsource,
                'meta' => $meta !== [] ? $meta : null,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = $this->winningContact($email);

            if ($existing instanceof Contact) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function existingContact(string $email): ?Contact
    {
        return Contact::query()
            ->where('email', $email)
            ->first();
    }

    private function winningContact(string $email): ?Contact
    {
        return Contact::query()
            ->where('email', $email)
            ->lockForUpdate()
            ->first();
    }

    private function normalizedEmail(string $email): string
    {
        $email = strtolower(trim($email));

        if ($email === '' || mb_strlen($email) > 255) {
            throw new InvalidArgumentException(
                'Contact email must be a non-empty value no longer than 255 characters.',
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(
                "Contact email [{$email}] is invalid.",
            );
        }

        return $email;
    }

    private function requiredString(
        string $value,
        string $label,
        int $maximumLength,
    ): string {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                "Contact {$label} cannot be empty.",
            );
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "Contact {$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }

    private function nullableString(
        ?string $value,
        string $label,
        int $maximumLength,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "Contact {$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique constraint')
            || str_contains(strtolower($exception->getMessage()), 'duplicate entry');
    }
}