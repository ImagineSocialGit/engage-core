<?php

namespace App\Support\ModuleFacts;

use App\Support\ModuleFacts\Contracts\ModuleFactProvider;
use App\Support\ModuleFacts\Data\ModuleFactDefinition;
use App\Support\ModuleFacts\Data\ModuleFactQuery;
use App\Support\ModuleFacts\Enums\ModuleFactCapability;
use App\Support\ModuleFacts\Enums\ModuleFactType;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ModuleFactRegistry
{
    public const PROVIDER_TAG = 'module_facts.providers';

    /** @var array<string, ModuleFactDefinition>|null */
    private ?array $definitions = null;

    /** @var array<string, string>|null */
    private ?array $aliases = null;

    public function __construct(private readonly Container $container) {}

    /** @return array<int, ModuleFactDefinition> */
    public function all(): array
    {
        $this->load();

        return array_values($this->definitions ?? []);
    }

    /**
     * @param class-string<Model>|null $subject
     * @return array<int, ModuleFactDefinition>
     */
    public function matching(
        ?string $subject = null,
        ?ModuleFactType $type = null,
        ?ModuleFactCapability $capability = null,
    ): array {
        return array_values(array_filter(
            $this->all(),
            fn (ModuleFactDefinition $definition): bool => ($subject === null || $definition->subject === $subject)
                && ($type === null || $definition->type === $type)
                && ($capability === null || $definition->has($capability)),
        ));
    }

    public function find(string $key): ?ModuleFactDefinition
    {
        $this->load();
        $canonical = $this->aliases[$key] ?? $key;

        return $this->definitions[$canonical] ?? null;
    }

    public function require(string $key): ModuleFactDefinition
    {
        return $this->find($key)
            ?? throw new InvalidArgumentException("Module fact [{$key}] is not registered.");
    }

    public function canonicalKey(string $key): string
    {
        return $this->require($key)->key;
    }

    /**
     * @param class-string<Model>|null $subject
     * @return array<string, string>
     */
    public function acceptedKeys(
        ?string $subject = null,
        ?ModuleFactType $type = null,
        ?ModuleFactCapability $capability = null,
    ): array {
        $keys = [];

        foreach ($this->matching($subject, $type, $capability) as $definition) {
            $keys[$definition->key] = $definition->key;

            foreach ($definition->aliases as $alias) {
                $keys[$alias] = $definition->key;
            }
        }

        ksort($keys);

        return $keys;
    }

    public function resolve(string $key, Model $subject): mixed
    {
        $definition = $this->require($key);
        $subjectClass = $definition->subject;

        if (! $subject instanceof $subjectClass) {
            throw new InvalidArgumentException(sprintf(
                'Module fact [%s] cannot resolve subject [%s].',
                $definition->key,
                $subject::class,
            ));
        }

        return $definition->valueResolver->resolve($subject);
    }

    /** @param Builder<*> $query */
    public function apply(string $key, Builder $query, ModuleFactQuery $factQuery): void
    {
        $definition = $this->require($key);

        if (! $definition->queryResolver) {
            throw new InvalidArgumentException("Module fact [{$definition->key}] does not support query filtering.");
        }

        $definition->queryResolver->apply($query, $factQuery);
    }

    private function load(): void
    {
        if ($this->definitions !== null) {
            return;
        }

        $this->definitions = [];
        $this->aliases = [];

        foreach ($this->container->tagged(self::PROVIDER_TAG) as $provider) {
            if (! $provider instanceof ModuleFactProvider) {
                throw new InvalidArgumentException(sprintf(
                    'Tagged module fact provider [%s] must implement [%s].',
                    $provider::class,
                    ModuleFactProvider::class,
                ));
            }

            foreach ($provider->facts() as $definition) {
                if (! $definition instanceof ModuleFactDefinition) {
                throw new InvalidArgumentException(sprintf(
                    'Module fact provider [%s] returned an invalid definition.',
                    $provider::class,
                ));
                }

                if (isset($this->definitions[$definition->key]) || isset($this->aliases[$definition->key])) {
                    throw new InvalidArgumentException("Duplicate module fact key [{$definition->key}].");
                }

                $this->definitions[$definition->key] = $definition;

                foreach ($definition->aliases as $alias) {
                    if ($alias === $definition->key
                        || isset($this->definitions[$alias])
                        || isset($this->aliases[$alias])
                    ) {
                        throw new InvalidArgumentException("Duplicate module fact alias [{$alias}].");
                    }

                    $this->aliases[$alias] = $definition->key;
                }
            }
        }

        ksort($this->definitions);
    }
}