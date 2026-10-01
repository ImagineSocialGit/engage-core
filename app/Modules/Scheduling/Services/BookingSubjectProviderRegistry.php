<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Contracts\BookingSubjectProvider;
use App\Modules\Scheduling\Services\BookingSubjects\ContactBookingSubjectProvider;
use App\Modules\Scheduling\Services\BookingSubjects\GenericBookingSubjectProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

final class BookingSubjectProviderRegistry
{
    public const TAG = 'scheduling.booking_subject_providers';

    public function __construct(
        private readonly Container $container,
        private readonly GenericBookingSubjectProvider $generic,
        private readonly ContactBookingSubjectProvider $contact,
    ) {}

    /** @return array<string, BookingSubjectProvider> */
    public function all(): array
    {
        $providers = [
            trim($this->generic->key()) => $this->generic,
            trim($this->contact->key()) => $this->contact,
        ];

        foreach ($this->container->tagged(self::TAG) as $provider) {
            if (! $provider instanceof BookingSubjectProvider) {
                throw new LogicException(
                    'Scheduling booking subject providers must implement BookingSubjectProvider.',
                );
            }

            $key = trim($provider->key());
            $label = trim($provider->label());

            if (preg_match('/\A[a-z0-9][a-z0-9_]{0,63}\z/', $key) !== 1) {
                throw new LogicException(
                    'Scheduling booking subject provider keys must be 1-64 lowercase letters, numbers, or underscores.',
                );
            }

            if ($label === '') {
                throw new LogicException(
                    "Scheduling booking subject provider [{$key}] requires a non-empty label.",
                );
            }

            if (isset($providers[$key])) {
                throw new LogicException(
                    "Duplicate Scheduling booking subject provider [{$key}].",
                );
            }

            $providers[$key] = $provider;
        }

        ksort($providers);

        return $providers;
    }

    public function provider(string $key): BookingSubjectProvider
    {
        $key = trim($key);
        $provider = $this->all()[$key] ?? null;

        if (! $provider instanceof BookingSubjectProvider) {
            throw new InvalidArgumentException(
                "Scheduling booking subject provider [{$key}] is unavailable.",
            );
        }

        return $provider;
    }

    /** @return array<int, array{key:string, label:string}> */
    public function definitions(): array
    {
        return array_values(array_map(
            static fn (BookingSubjectProvider $provider): array => [
                'key' => trim($provider->key()),
                'label' => trim($provider->label()),
            ],
            $this->all(),
        ));
    }

    public function accepts(string $key, Model $subject): bool
    {
        return $this->provider($key)->accepts($subject);
    }

    public function assertAccepts(string $key, Model $subject): void
    {
        if ($this->accepts($key, $subject)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Booking subject [%s:%s] is not accepted by Scheduling subject provider [%s].',
            $subject->getMorphClass(),
            (string) $subject->getKey(),
            trim($key),
        ));
    }
}