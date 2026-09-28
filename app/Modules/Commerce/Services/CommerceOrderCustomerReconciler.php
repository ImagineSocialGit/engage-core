<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Data\CommerceOrderCustomerResolution;
use App\Modules\Commerce\Data\CommerceOrderCustomerSnapshotData;
use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Core\Actions\Contacts\ResolveContactByEmailAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactPhoneNormalizer;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;

final class CommerceOrderCustomerReconciler
{
    public function __construct(
        private readonly ResolveContactByEmailAction $resolveContactByEmail,
        private readonly ContactPhoneNormalizer $phoneNormalizer,
    ) {}

    public function reconcile(
        string $providerKey,
        CommerceOrderCustomerSnapshotData $snapshot,
        ?DateTimeInterface $orderedAt = null,
    ): CommerceOrderCustomerResolution {
        $providerKey = trim($providerKey);

        if ($providerKey === '') {
            throw new InvalidArgumentException(
                'Commerce customer reconciliation provider key cannot be empty.',
            );
        }

        $externalId = $this->nullableString($snapshot->externalId);

        if ($externalId === null) {
            $contact = $this->resolveContact(
                snapshot: $snapshot,
                providerKey: $providerKey,
                externalCustomerId: null,
            );

            return new CommerceOrderCustomerResolution(
                commerceCustomerId: null,
                contactId: $contact?->getKey() !== null
                    ? (int) $contact->getKey()
                    : null,
            );
        }

        $seed = CommerceCustomer::withTrashed()->firstOrCreate(
            [
                'provider' => $providerKey,
                'external_id' => $externalId,
            ],
            [
                'source' => 'provider',
                'status' => CommerceCustomer::STATUS_ACTIVE,
            ],
        );

        $customer = CommerceCustomer::withTrashed()
            ->whereKey($seed->getKey())
            ->lockForUpdate()
            ->first();

        if (! $customer instanceof CommerceCustomer) {
            throw new RuntimeException(
                'Commerce customer disappeared during provider reconciliation.',
            );
        }

        if ($customer->trashed()) {
            $customer->restore();
        }

        $contact = $this->linkedContact($customer);

        if (! $contact instanceof Contact) {
            $contact = $this->resolveContact(
                snapshot: $snapshot,
                providerKey: $providerKey,
                externalCustomerId: $externalId,
            );
        }

        $customer->fill([
            'contact_id' => $contact?->getKey(),
            'first_name' => $this->nullableString($snapshot->firstName),
            'last_name' => $this->nullableString($snapshot->lastName),
            'name' => $this->nullableString($snapshot->name),
            'email' => $this->normalizedEmail($snapshot->email),
            'phone' => $this->nullableString($snapshot->phone),
            'status' => CommerceCustomer::STATUS_ACTIVE,
            'currency' => $this->currency($snapshot->currency),
            'first_ordered_at' => $this->earliest(
                $customer->first_ordered_at,
                $orderedAt,
            ),
            'last_ordered_at' => $this->latest(
                $customer->last_ordered_at,
                $orderedAt,
            ),
            'total_orders' => $snapshot->totalOrders
                ?? (int) $customer->total_orders,
            'total_spent_cents' => $snapshot->totalSpentCents
                ?? (int) $customer->total_spent_cents,
            'source' => 'provider',
            'provider' => $providerKey,
            'external_id' => $externalId,
            'external_url' => $this->nullableString($snapshot->externalUrl),
            'raw_payload' => null,
            'meta' => $snapshot->meta !== [] ? $snapshot->meta : null,
        ]);

        $customer->save();

        return new CommerceOrderCustomerResolution(
            commerceCustomerId: (int) $customer->getKey(),
            contactId: $contact?->getKey() !== null
                ? (int) $contact->getKey()
                : null,
        );
    }

    private function linkedContact(CommerceCustomer $customer): ?Contact
    {
        if ($customer->contact_id === null) {
            return null;
        }

        $contact = Contact::withTrashed()->find($customer->contact_id);

        if (! $contact instanceof Contact) {
            return null;
        }

        if ($contact->trashed()) {
            $contact->restore();
        }

        return $contact;
    }

    private function resolveContact(
        CommerceOrderCustomerSnapshotData $snapshot,
        string $providerKey,
        ?string $externalCustomerId,
    ): ?Contact {
        $email = $this->normalizedEmail($snapshot->email);

        if ($email === null) {
            return null;
        }

        $meta = [
            'commerce_provider' => $providerKey,
        ];

        if ($externalCustomerId !== null) {
            $meta['commerce_customer_external_id'] = $externalCustomerId;
        }

        return $this->resolveContactByEmail->handle(
            email: $email,
            name: $this->nullableString($snapshot->name),
            phone: $this->contactPhone($snapshot->phone),
            source: 'commerce',
            subsource: $providerKey,
            meta: $meta,
        );
    }

    private function contactPhone(?string $phone): ?string
    {
        $phone = $this->nullableString($phone);

        if ($phone === null) {
            return null;
        }

        try {
            return $this->phoneNormalizer->normalize($phone);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function normalizedEmail(?string $email): ?string
    {
        $email = $this->nullableString($email);

        return $email !== null ? strtolower($email) : null;
    }

    private function currency(?string $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $value = strtoupper($value);

        if (preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'Commerce customer currency must be a three-letter code.',
            );
        }

        return $value;
    }

    private function earliest(
        mixed $current,
        ?DateTimeInterface $candidate,
    ): mixed {
        if ($candidate === null) {
            return $current;
        }

        if (! $current instanceof DateTimeInterface) {
            return $candidate;
        }

        return $candidate < $current ? $candidate : $current;
    }

    private function latest(
        mixed $current,
        ?DateTimeInterface $candidate,
    ): mixed {
        if ($candidate === null) {
            return $current;
        }

        if (! $current instanceof DateTimeInterface) {
            return $candidate;
        }

        return $candidate > $current ? $candidate : $current;
    }

    private function nullableString(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}