<?php

namespace App\Support\ModuleIntegrations\Documents\Portal;

use App\Modules\Portal\Models\PortalUser;
use App\Support\ModuleIntegrations\Documents\Portal\Contracts\PortalDocumentSubjectProvider;
use App\Support\ModuleIntegrations\Documents\Portal\Data\PortalDocumentSubject;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class PortalDocumentSubjectRegistry
{
    public const TAG = 'documents.portal_subject_providers';

    public function __construct(private readonly iterable $providers) {}

    /** @return array<int, PortalDocumentSubject> */
    public function forUser(PortalUser $user): array
    {
        $definitions = [];

        foreach ($this->providers as $provider) {
            if (! $provider instanceof PortalDocumentSubjectProvider) {
                continue;
            }

            foreach ($provider->definitions($user) as $definition) {
                if (! $definition instanceof PortalDocumentSubject) {
                    throw new LogicException('Portal document subject providers must yield PortalDocumentSubject instances.');
                }

                $key = $definition->key();

                if (array_key_exists($key, $definitions)) {
                    continue;
                }

                $definitions[$key] = $definition;
            }
        }

        $definitions = array_values($definitions);
        usort(
            $definitions,
            static fn (PortalDocumentSubject $a, PortalDocumentSubject $b): int => [
                mb_strtolower($a->typeLabel),
                mb_strtolower($a->label),
                $a->key(),
            ] <=> [
                mb_strtolower($b->typeLabel),
                mb_strtolower($b->label),
                $b->key(),
            ],
        );

        return $definitions;
    }

    public function allows(PortalUser $user, Model $subject): bool
    {
        if (! $subject->exists || $subject->getKey() === null) {
            return false;
        }

        $key = $subject->getMorphClass().':'.$subject->getKey();

        return collect($this->forUser($user))
            ->contains(static fn (PortalDocumentSubject $definition): bool => $definition->key() === $key);
    }
}