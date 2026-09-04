<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\ReusableMessageTemplateAuthoringOptionContributor;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringOption;
use InvalidArgumentException;

final class ReusableMessageTemplateAuthoringGuide
{
    /** @var array<int, ReusableMessageTemplateAuthoringOptionContributor> */
    private array $contributors;

    /**
     * @param iterable<int, ReusableMessageTemplateAuthoringOptionContributor> $contributors
     */
    public function __construct(iterable $contributors = [])
    {
        $this->contributors = [];

        foreach ($contributors as $contributor) {
            if ($contributor instanceof ReusableMessageTemplateAuthoringOptionContributor) {
                $this->contributors[] = $contributor;
            }
        }
    }

    /** @return array<int, ReusableMessageTemplateAuthoringOption> */
    public function options(): array
    {
        $options = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor->options() as $option) {
                if (! $option instanceof ReusableMessageTemplateAuthoringOption) {
                    continue;
                }

                $key = trim($option->key);

                if (preg_match('/\A[a-z][a-z0-9_.:-]*\z/', $key) !== 1) {
                    throw new InvalidArgumentException(
                        "Reusable message authoring option key [{$key}] is invalid.",
                    );
                }

                if (isset($options[$key])) {
                    throw new InvalidArgumentException(
                        "Reusable message authoring option [{$key}] is registered more than once.",
                    );
                }

                if (! in_array($option->channel, ['email', 'sms'], true)) {
                    throw new InvalidArgumentException(
                        "Reusable message authoring option [{$key}] has unsupported channel [{$option->channel}].",
                    );
                }

                $options[$key] = $option;
            }
        }

        uasort($options, static fn (
            ReusableMessageTemplateAuthoringOption $left,
            ReusableMessageTemplateAuthoringOption $right,
        ): int => [
            $left->order,
            mb_strtolower($left->context->moduleLabel),
            mb_strtolower($left->label),
            $left->key,
        ] <=> [
            $right->order,
            mb_strtolower($right->context->moduleLabel),
            mb_strtolower($right->label),
            $right->key,
        ]);

        return array_values($options);
    }

    public function find(?string $key): ?ReusableMessageTemplateAuthoringOption
    {
        $key = is_string($key) ? trim($key) : '';

        if ($key === '') {
            return null;
        }

        foreach ($this->options() as $option) {
            if ($option->key === $key) {
                return $option;
            }
        }

        return null;
    }
}