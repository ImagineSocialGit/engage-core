<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\MessageTemplateDefinitionContributor;
use App\Modules\Messaging\Data\MessageTemplateDefinitionContribution;
use App\Support\Modules\ModuleManager;
use InvalidArgumentException;

final class MessageTemplateDefinitionRegistry
{
    /** @var array<int, MessageTemplateDefinitionContributor> */
    private array $contributors;

    /** @var array<string, MessageTemplateDefinitionContributor> */
    private array $ownersByScope = [];

    /**
     * @param iterable<MessageTemplateDefinitionContributor> $contributors
     */
    public function __construct(
        iterable $contributors,
        private readonly ModuleManager $moduleManager,
    ) {
        $this->contributors = [];

        foreach ($contributors as $contributor) {
            if (! $contributor instanceof MessageTemplateDefinitionContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Message template definition contributor [%s] must implement [%s].',
                    get_debug_type($contributor),
                    MessageTemplateDefinitionContributor::class,
                ));
            }

            $moduleKey = $this->normalize($contributor->moduleKey());

            if ($moduleKey === '' || ! $this->moduleManager->known($moduleKey)) {
                throw new InvalidArgumentException(
                    sprintf('Message template definition contributor [%s] declares unknown module [%s].', $contributor::class, $moduleKey)
                );
            }

            foreach ($contributor->ownedScopes() as $scope) {
                $scope = $this->normalize($scope);

                if ($scope === '') {
                    continue;
                }

                if (isset($this->ownersByScope[$scope])) {
                    $existing = $this->ownersByScope[$scope];

                    throw new InvalidArgumentException(
                        sprintf('Message template scope [%s] is owned by both [%s] and [%s].', $scope, $existing::class, $contributor::class)
                    );
                }

                $this->ownersByScope[$scope] = $contributor;
            }

            $this->contributors[] = $contributor;
        }
    }

    public function ownerModuleForScope(string $scope): string
    {
        $owner = $this->ownersByScope[$this->normalize($scope)] ?? null;

        return $owner instanceof MessageTemplateDefinitionContributor
            ? $this->normalize($owner->moduleKey())
            : 'messaging';
    }

    public function ownerLabelForScope(string $scope): string
    {
        $owner = $this->ownersByScope[$this->normalize($scope)] ?? null;

        return $owner instanceof MessageTemplateDefinitionContributor
            ? trim($owner->moduleLabel())
            : 'Messaging';
    }

    public function scopeOwnerEnabled(string $scope): bool
    {
        return in_array(
            $this->ownerModuleForScope($scope),
            $this->moduleManager->enabledKeysWithDependencies(),
            true,
        );
    }

    /**
     * @return iterable<int, array{
     *     module_key: string,
     *     module_label: string,
     *     contribution: MessageTemplateDefinitionContribution
     * }>
     */
    public function activeContributions(): iterable
    {
        $enabledModules = $this->moduleManager->enabledKeysWithDependencies();

        foreach ($this->contributors as $contributor) {
            $moduleKey = $this->normalize($contributor->moduleKey());

            if (! in_array($moduleKey, $enabledModules, true)) {
                continue;
            }

            foreach ($contributor->contributions() as $contribution) {
                if (! $contribution instanceof MessageTemplateDefinitionContribution) {
                    throw new InvalidArgumentException(sprintf(
                        'Message template definition contributor [%s] yielded [%s] instead of [%s].',
                        $contributor::class,
                        get_debug_type($contribution),
                        MessageTemplateDefinitionContribution::class,
                    ));
                }

                $scope = $this->normalize($contribution->scope);

                if (! $this->contributionBelongsToModule(
                    moduleKey: $moduleKey,
                    scope: $scope,
                    definitions: $contribution->definitions,
                )) {
                    throw new InvalidArgumentException(
                        sprintf('Message template definition contributor [%s] yielded definitions it does not own for scope [%s].', $contributor::class, $scope)
                    );
                }

                yield [
                    'module_key' => $moduleKey,
                    'module_label' => trim($contributor->moduleLabel()),
                    'contribution' => $contribution,
                ];
            }
        }
    }

    /**
     * @param array<string, mixed> $definitions
     */
    private function contributionBelongsToModule(
        string $moduleKey,
        string $scope,
        array $definitions,
    ): bool {
        if ($this->ownerModuleForScope($scope) === $moduleKey) {
            return true;
        }

        return $moduleKey === 'campaigns'
            && $this->containsOnlyCampaignDefinitions($scope, $definitions);
    }

    /**
     * Campaigns owns campaign-step definitions independently from the business
     * scope those messages use. Standard definitions in that same scope remain
     * owned by the scope contributor.
     *
     * @param array<string, mixed> $definitions
     */
    private function containsOnlyCampaignDefinitions(
        string $scope,
        array $definitions,
    ): bool {
        if ($definitions === []) {
            return false;
        }

        if ($this->normalize($scope) !== 'webinar') {
            return count($definitions) === 1
                && array_key_exists('campaigns', $definitions)
                && is_array($definitions['campaigns']);
        }

        foreach ($definitions as $setDefinitions) {
            if (! is_array($setDefinitions)
                || count($setDefinitions) !== 1
                || ! array_key_exists('campaigns', $setDefinitions)
                || ! is_array($setDefinitions['campaigns'])
            ) {
                return false;
            }
        }

        return true;
    }

    private function normalize(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}