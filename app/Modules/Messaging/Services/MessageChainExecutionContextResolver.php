<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\MessageChainExecutionContextProvider;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MessageChainExecutionContextResolver
{
    /**
     * @param iterable<int, MessageChainExecutionContextProvider> $providers
     */
    public function __construct(
        private readonly iterable $providers = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(MessageChainEnrollment $enrollment): array
    {
        $enrollment->loadMissing([
            'recipient',
            'context',
            'origin',
        ]);

        $recipient = $enrollment->recipient;

        if (! $recipient instanceof Model) {
            throw new InvalidArgumentException(
                "MessageChainEnrollment [{$enrollment->getKey()}] has no resolvable recipient.",
            );
        }

        $values = [
            'recipient' => $this->modelValues($recipient),
            $this->modelKey($recipient) => $this->modelValues($recipient),
        ];

        $context = $enrollment->context;

        if ($context instanceof Model) {
            $values['context'] = $this->modelValues($context);
            $values[$this->modelKey($context)] = $this->modelValues($context);
        }

        $origin = $enrollment->origin;

        if ($origin instanceof Model) {
            $values['origin'] = $this->modelValues($origin);
            $values[$this->modelKey($origin)] = $this->modelValues($origin);
        }

        foreach ($this->providers as $provider) {
            if (! $provider instanceof MessageChainExecutionContextProvider) {
                throw new InvalidArgumentException(
                    'Message-chain execution-context providers must implement MessageChainExecutionContextProvider.',
                );
            }

            if (! $provider->supports($enrollment)) {
                continue;
            }

            $values = array_replace_recursive(
                $values,
                $provider->values($enrollment),
            );
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function modelValues(Model $model): array
    {
        return $model->attributesToArray();
    }

    private function modelKey(Model $model): string
    {
        return Str::snake(class_basename($model));
    }
}