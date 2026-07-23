<?php

namespace App\Support\Queues;

use InvalidArgumentException;

final class QueueContract
{
    public const QUEUES = [
        'default',
        'notifications',
        'confirmation_messages',
        'opt_in_messages',
        'reminders',
        'post_event',
        'marketing',
        'emails',
        'sms',
        'webinars',
        'webhooks',
    ];

    public function environment(): string
    {
        $environment = config('app.env', 'production');

        return is_string($environment) && trim($environment) !== ''
            ? trim($environment)
            : 'production';
    }

    public function resolve(?string $queue): string
    {
        if (is_string($queue) && trim($queue) !== '') {
            return trim($queue);
        }

        $connection = $this->defaultConnection();
        $defaultQueue = config("queue.connections.{$connection}.queue", 'default');

        return is_string($defaultQueue) && trim($defaultQueue) !== ''
            ? trim($defaultQueue)
            : 'default';
    }

    public function isSupported(?string $queue): bool
    {
        return in_array($this->resolve($queue), self::QUEUES, true);
    }

    public function isConsumed(?string $queue): bool
    {
        return in_array($this->resolve($queue), $this->consumedQueues(), true);
    }

    public function assertDispatchable(?string $queue): string
    {
        $queue = $this->resolve($queue);

        if (! $this->isSupported($queue)) {
            throw new InvalidArgumentException(
                "Queue [{$queue}] is not registered in the executable queue contract.",
            );
        }

        if (! $this->hasHorizonEnvironmentConfiguration()) {
            if ($this->environment() === 'testing') {
                return $queue;
            }

            throw new InvalidArgumentException(
                "Horizon has no supervisor configuration for environment [{$this->environment()}].",
            );
        }

        if (! $this->isConsumed($queue)) {
            throw new InvalidArgumentException(
                "Queue [{$queue}] is registered but is not consumed by Horizon in environment [{$this->environment()}].",
            );
        }

        return $queue;
    }

    public function hasHorizonEnvironmentConfiguration(): bool
    {
        return $this->environmentConfiguration() !== null;
    }

    /**
     * @return array<int, string>
     */
    public function consumedQueues(): array
    {
        $queues = [];

        foreach ($this->resolvedSupervisors() as $supervisor) {
            if (($supervisor['connection'] ?? null) !== $this->defaultConnection()) {
                continue;
            }

            $queues = array_merge(
                $queues,
                $this->normalizeQueueNames($supervisor['queue'] ?? []),
            );
        }

        return array_values(array_unique($queues));
    }

    /**
     * @return array<int, array{
     *     code: string,
     *     message: string,
     *     path: string,
     *     context: array<string, mixed>
     * }>
     */
    public function validationIssues(): array
    {
        $issues = [];
        $referenceQueues = config('reference.keys.queues', []);
        $referenceQueueNames = is_array($referenceQueues)
            ? $this->normalizeQueueNames(array_keys($referenceQueues))
            : [];

        foreach (array_diff(self::QUEUES, $referenceQueueNames) as $queue) {
            $issues[] = $this->issue(
                code: 'reference_queue_missing',
                message: "Executable queue [{$queue}] is missing from the reference queue registry.",
                path: "reference.keys.queues.{$queue}",
                context: ['queue' => $queue],
            );
        }

        foreach (array_diff($referenceQueueNames, self::QUEUES) as $queue) {
            $issues[] = $this->issue(
                code: 'reference_queue_unregistered',
                message: "Reference queue [{$queue}] is not registered in the executable queue contract.",
                path: "reference.keys.queues.{$queue}",
                context: ['queue' => $queue],
            );
        }

        $defaultQueue = $this->resolve(null);

        if (! in_array($defaultQueue, self::QUEUES, true)) {
            $issues[] = $this->issue(
                code: 'default_queue_unregistered',
                message: "Default queue [{$defaultQueue}] is not registered in the executable queue contract.",
                path: "queue.connections.{$this->defaultConnection()}.queue",
                context: [
                    'connection' => $this->defaultConnection(),
                    'queue' => $defaultQueue,
                ],
            );
        }

        if (! $this->hasHorizonEnvironmentConfiguration()) {
            if ($this->environment() !== 'testing') {
                $issues[] = $this->issue(
                    code: 'horizon_environment_missing',
                    message: "Horizon has no supervisor configuration for environment [{$this->environment()}].",
                    path: "horizon.environments.{$this->environment()}",
                    context: [
                        'environment' => $this->environment(),
                    ],
                );
            }

            return $issues;
        }

        $supervisorConnections = array_values(array_unique(array_filter(array_map(
            static fn (array $supervisor): ?string => is_string($supervisor['connection'] ?? null)
                && trim($supervisor['connection']) !== ''
                    ? trim($supervisor['connection'])
                    : null,
            $this->resolvedSupervisors(),
        ))));

        if (! in_array($this->defaultConnection(), $supervisorConnections, true)) {
            $issues[] = $this->issue(
                code: 'default_connection_unconsumed',
                message: "Default queue connection [{$this->defaultConnection()}] is not consumed by an active Horizon supervisor.",
                path: "horizon.environments.{$this->environment()}",
                context: [
                    'environment' => $this->environment(),
                    'connection' => $this->defaultConnection(),
                    'supervisor_connections' => $supervisorConnections,
                ],
            );
        }

        $consumedQueues = $this->consumedQueues();

        foreach (array_diff($consumedQueues, self::QUEUES) as $queue) {
            $issues[] = $this->issue(
                code: 'consumed_queue_unregistered',
                message: "Horizon consumes queue [{$queue}], but it is not registered in the executable queue contract.",
                path: "horizon.environments.{$this->environment()}",
                context: [
                    'environment' => $this->environment(),
                    'queue' => $queue,
                ],
            );
        }

        foreach (array_diff(self::QUEUES, $consumedQueues) as $queue) {
            $issues[] = $this->issue(
                code: 'registered_queue_unconsumed',
                message: "Executable queue [{$queue}] is not consumed by Horizon in environment [{$this->environment()}].",
                path: "horizon.environments.{$this->environment()}",
                context: [
                    'environment' => $this->environment(),
                    'queue' => $queue,
                ],
            );
        }

        return $issues;
    }

    private function defaultConnection(): string
    {
        $connection = config('queue.default', 'redis');

        return is_string($connection) && trim($connection) !== ''
            ? trim($connection)
            : 'redis';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function environmentConfiguration(): ?array
    {
        $environments = config('horizon.environments', []);

        if (! is_array($environments)) {
            return null;
        }

        $configuration = $environments[$this->environment()]
            ?? $environments['*']
            ?? null;

        return is_array($configuration) ? $configuration : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolvedSupervisors(): array
    {
        $environmentConfiguration = $this->environmentConfiguration();

        if ($environmentConfiguration === null) {
            return [];
        }

        $defaults = config('horizon.defaults', []);
        $defaults = is_array($defaults) ? $defaults : [];
        $names = array_values(array_unique(array_merge(
            array_keys($defaults),
            array_keys($environmentConfiguration),
        )));
        $supervisors = [];

        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }

            $default = is_array($defaults[$name] ?? null)
                ? $defaults[$name]
                : [];
            $environment = is_array($environmentConfiguration[$name] ?? null)
                ? $environmentConfiguration[$name]
                : [];

            $supervisors[] = array_replace($default, $environment);
        }

        return $supervisors;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeQueueNames(mixed $queues): array
    {
        if (is_string($queues)) {
            $queues = explode(',', $queues);
        }

        if (! is_array($queues)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $queue): ?string => is_string($queue)
                && trim($queue) !== ''
                    ? trim($queue)
                    : null,
            $queues,
        ))));
    }

    /**
     * @param array<string, mixed> $context
     * @return array{
     *     code: string,
     *     message: string,
     *     path: string,
     *     context: array<string, mixed>
     * }
     */
    private function issue(
        string $code,
        string $message,
        string $path,
        array $context,
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'path' => $path,
            'context' => $context,
        ];
    }
}