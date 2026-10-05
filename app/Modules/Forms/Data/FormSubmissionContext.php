<?php

namespace App\Modules\Forms\Data;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

final readonly class FormSubmissionContext
{
    /** @var array<string, mixed> */
    public array $attributes;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public HostedFormContextReference $reference,
        public Model $subject,
        array $attributes = [],
    ) {
        if (! $subject->exists) {
            throw new InvalidArgumentException(
                'Form submission context subject must be a persisted model.',
            );
        }

        $subjectId = $subject->getKey();

        if (! is_int($subjectId)
            && ! (is_string($subjectId) && ctype_digit($subjectId))
        ) {
            throw new InvalidArgumentException(
                'Form submission context subject must use an integer primary key.',
            );
        }

        if ((int) $subjectId < 1) {
            throw new InvalidArgumentException(
                'Form submission context subject must have a positive primary key.',
            );
        }

        try {
            json_encode($attributes, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Form submission context attributes must be JSON-encodable.',
                previous: $exception,
            );
        }

        $this->attributes = $attributes;
    }

    public function subjectId(): int
    {
        return (int) $this->subject->getKey();
    }

    /** @return array<string, mixed> */
    public function identity(): array
    {
        return [
            'key' => $this->reference->key,
            'reference' => $this->reference->reference,
            'subject' => [
                'type' => $this->subject->getMorphClass(),
                'id' => $this->subjectId(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return [
            ...$this->identity(),
            'attributes' => $this->attributes,
        ];
    }
}