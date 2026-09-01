<?php

namespace App\Modules\Messaging\Services;

use App\Support\TokenContracts\Data\TokenSourceDefinition;
use App\Support\TokenContracts\TokenContractRegistry;
use Illuminate\Support\Str;

class MessageTemplateAuthoringFieldPresenter
{
    public function __construct(
        private readonly TokenContractRegistry $tokenContracts,
    ) {}

    /**
     * Return operator-facing fields for one executable message dispatch context.
     * The registry remains the authority; this service only presents its sources.
     *
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     fields: array<int, array{
     *         token: string,
     *         insert_token: string,
     *         syntax: string,
     *         label: string,
     *         description: string,
     *         example: ?string,
     *         owner: string
     *     }>
     * }>
     */
    public function groupsForContext(string $contextKey): array
    {
        $context = $this->tokenContracts->context($contextKey);
        $groups = [];

        foreach ($context->sourceTokens as $sourceToken) {
            $source = $this->tokenContracts->source($sourceToken);
            $groupKey = trim($source->owner);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'label' => $this->groupLabel($source),
                    'fields' => [],
                ];
            }

            $insertToken = $this->preferredInsertToken($source);

            $groups[$groupKey]['fields'][] = [
                'token' => $source->token,
                'insert_token' => $insertToken,
                'syntax' => '{'.$insertToken.'}',
                'label' => $source->label,
                'description' => $source->description,
                'example' => $source->example,
                'owner' => $source->owner,
            ];
        }

        return array_values($groups);
    }

    private function preferredInsertToken(TokenSourceDefinition $source): string
    {
        foreach ($source->aliases as $alias) {
            if (is_string($alias) && trim($alias) !== '') {
                return trim($alias);
            }
        }

        return $source->token;
    }

    private function groupLabel(TokenSourceDefinition $source): string
    {
        return match ($source->owner) {
            'core' => 'Contact',
            'campaigns' => 'Campaign',
            'webinars' => 'Webinar',
            'messaging' => 'Message',
            default => Str::headline($source->owner),
        };
    }
}