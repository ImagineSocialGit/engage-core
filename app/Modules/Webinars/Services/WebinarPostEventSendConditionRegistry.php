<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Contracts\WebinarPostEventSendCondition;
use App\Modules\Webinars\Models\WebinarRegistration;
use InvalidArgumentException;

final class WebinarPostEventSendConditionRegistry
{
    /** @var array<string, WebinarPostEventSendCondition> */
    private array $conditions = [];

    /** @param iterable<WebinarPostEventSendCondition> $conditions */
    public function __construct(iterable $conditions)
    {
        foreach ($conditions as $condition) {
            if (! $condition instanceof WebinarPostEventSendCondition || $condition->key() === ''
                || $condition->key() === 'always' || isset($this->conditions[$condition->key()])) {
                throw new InvalidArgumentException('Invalid or duplicate webinar send condition.');
            }

            $this->conditions[$condition->key()] = $condition;
        }
    }

    /** @return array<string, string> */
    public function options(): array
    {
        $options = ['always' => 'Always'];
        foreach ($this->conditions as $condition) {
            $options[$condition->key()] = $condition->label();
        }

        return $options;
    }

    public function has(string $key): bool
    {
        return $key === 'always' || isset($this->conditions[$key]);
    }

    public function matches(string $key, WebinarRegistration $registration, string $activation, string $dueAt): bool
    {
        if ($key === 'always') {
            return true;
        }

        $condition = $this->conditions[$key] ?? null;
        if (! $condition) {
            throw new InvalidArgumentException('Unknown webinar send condition.');
        }

        return $condition->matches($registration, $activation, $dueAt);
    }
}