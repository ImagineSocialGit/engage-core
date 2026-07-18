<?php

namespace App\Modules\Webinars\Data;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, ProviderWebinarData>
 */
final readonly class ProviderWebinarSnapshot implements Countable, IteratorAggregate
{
    /**
     * @param array<int, ProviderWebinarData> $webinars
     */
    private function __construct(
        public array $webinars,
        public bool $authoritative,
        public ?string $reason,
    ) {}

    /**
     * @param iterable<ProviderWebinarData> $webinars
     */
    public static function authoritative(iterable $webinars): self
    {
        return new self(
            webinars: self::normalizeWebinars($webinars),
            authoritative: true,
            reason: null,
        );
    }

    /**
     * @param iterable<ProviderWebinarData> $webinars
     */
    public static function nonAuthoritative(iterable $webinars, string $reason): self
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A non-authoritative provider Webinar snapshot requires a reason.');
        }

        return new self(
            webinars: self::normalizeWebinars($webinars),
            authoritative: false,
            reason: $reason,
        );
    }

    /**
     * @return Traversable<int, ProviderWebinarData>
     */
    public function getIterator(): Traversable
    {
        yield from $this->webinars;
    }

    public function count(): int
    {
        return count($this->webinars);
    }

    /**
     * @param iterable<ProviderWebinarData> $webinars
     * @return array<int, ProviderWebinarData>
     */
    private static function normalizeWebinars(iterable $webinars): array
    {
        $normalized = [];

        foreach ($webinars as $webinar) {
            if (! $webinar instanceof ProviderWebinarData) {
                throw new InvalidArgumentException(sprintf(
                    'Provider Webinar snapshots may contain only [%s] instances.',
                    ProviderWebinarData::class,
                ));
            }

            if (trim($webinar->externalId) === '') {
                throw new InvalidArgumentException('Provider Webinar snapshots require non-empty external IDs.');
            }

            $normalized[] = $webinar;
        }

        return $normalized;
    }
}
