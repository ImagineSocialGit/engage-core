<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Contracts\HostedFormContextResolver;
use App\Modules\Forms\Data\FormSubmissionContext;
use App\Modules\Forms\Data\HostedFormContextReference;
use DomainException;

final class HostedFormContextRegistry
{
    /** @var array<string, HostedFormContextResolver> */
    private array $resolvers = [];

    /**
     * @param iterable<HostedFormContextResolver> $resolvers
     */
    public function __construct(iterable $resolvers = [])
    {
        foreach ($resolvers as $resolver) {
            $key = strtolower(trim($resolver->key()));

            if ($key === '') {
                throw new DomainException(
                    'Hosted form context resolvers must declare a non-empty key.',
                );
            }

            if (isset($this->resolvers[$key])) {
                throw new DomainException(
                    "Duplicate hosted form context resolver [{$key}].",
                );
            }

            $this->resolvers[$key] = $resolver;
        }
    }

    public function resolve(
        HostedFormContextReference $reference,
    ): ?FormSubmissionContext {
        $resolver = $this->resolvers[$reference->key] ?? null;

        return $resolver?->resolve($reference);
    }
}