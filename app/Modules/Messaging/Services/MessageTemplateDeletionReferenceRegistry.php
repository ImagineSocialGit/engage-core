<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\MessageTemplateDeletionReferenceContributor;
use App\Modules\Messaging\Data\MessageTemplateDeletionReference;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class MessageTemplateDeletionReferenceRegistry
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @return Collection<int, MessageTemplateDeletionReference>
     */
    public function references(
        MessageTemplatePreset $preset,
        ?MessageTemplate $template,
    ): Collection {
        $references = collect();

        foreach ($this->container->tagged(MessageTemplateDeletionReferenceContributor::TAG) as $contributor) {
            if (! $contributor instanceof MessageTemplateDeletionReferenceContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Tagged message-template deletion reference contributor [%s] must implement [%s].',
                    get_debug_type($contributor),
                    MessageTemplateDeletionReferenceContributor::class,
                ));
            }

            foreach ($contributor->references($preset, $template) as $reference) {
                if (! $reference instanceof MessageTemplateDeletionReference) {
                    throw new InvalidArgumentException(sprintf(
                        'Message-template deletion reference contributor [%s] returned [%s] instead of [%s].',
                        $contributor::class,
                        get_debug_type($reference),
                        MessageTemplateDeletionReference::class,
                    ));
                }

                $references->push($reference);
            }
        }

        return $references
            ->unique(fn (MessageTemplateDeletionReference $reference): string => $reference->fingerprint())
            ->values();
    }
}