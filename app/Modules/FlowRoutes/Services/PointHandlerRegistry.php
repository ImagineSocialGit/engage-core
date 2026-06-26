<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\FlowRoutes\Contracts\PointHandler;
use InvalidArgumentException;

class PointHandlerRegistry
{
    /**
     * @var array<string, PointHandler>
     */
    private array $handlers = [];

    /**
     * @param iterable<int, PointHandler> $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->register($handler);
        }
    }

    public function register(PointHandler $handler): void
    {
        $type = trim($handler->type());

        if ($type === '') {
            throw new InvalidArgumentException('Point handler type cannot be empty.');
        }

        if (isset($this->handlers[$type])) {
            throw new InvalidArgumentException("Point handler already registered for type [{$type}].");
        }

        $this->handlers[$type] = $handler;
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    public function resolve(string $type): ?PointHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function registeredTypes(): array
    {
        return array_values(array_keys($this->handlers));
    }
}