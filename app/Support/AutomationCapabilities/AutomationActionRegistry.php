<?php

namespace App\Support\AutomationCapabilities;

use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use InvalidArgumentException;

class AutomationActionRegistry
{
    /** @var array<string, AutomationActionHandler>|null */
    private ?array $resolved = null;

    /**
     * @param iterable<int, AutomationActionHandler> $handlers
     */
    public function __construct(
        private readonly iterable $handlers,
    ) {}

    public function has(string $key): bool
    {
        return isset($this->all()[trim($key)]);
    }

    public function get(string $key): ?AutomationActionHandler
    {
        return $this->all()[trim($key)] ?? null;
    }

    /**
     * @return array<string, AutomationActionHandler>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach ($this->handlers as $handler) {
            if (! $handler instanceof AutomationActionHandler) {
                throw new InvalidArgumentException(sprintf(
                    'Automation action registry received invalid handler [%s].',
                    get_debug_type($handler),
                ));
            }

            $key = trim($handler->key());

            if ($key === '') {
                throw new InvalidArgumentException('Automation action handler key cannot be empty.');
            }

            if (isset($resolved[$key])) {
                throw new InvalidArgumentException("Duplicate automation action handler key [{$key}].");
            }

            $resolved[$key] = $handler;
        }

        ksort($resolved);

        return $this->resolved = $resolved;
    }
}
