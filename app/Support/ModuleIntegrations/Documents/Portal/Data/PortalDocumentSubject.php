<?php

namespace App\Support\ModuleIntegrations\Documents\Portal\Data;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class PortalDocumentSubject
{
    public function __construct(
        public Model $subject,
        public string $label,
        public string $typeLabel,
    ) {
        if (! $subject->exists || $subject->getKey() === null) {
            throw new InvalidArgumentException('Portal document subjects must be persisted models.');
        }

        if (trim($label) === '' || trim($typeLabel) === '') {
            throw new InvalidArgumentException('Portal document subjects require a label and type label.');
        }
    }

    public function key(): string
    {
        return $this->subject->getMorphClass().':'.$this->subject->getKey();
    }
}