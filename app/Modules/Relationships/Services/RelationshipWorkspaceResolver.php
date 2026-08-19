<?php

namespace App\Modules\Relationships\Services;

use App\Modules\Core\Services\SiteSettings\SiteSettingResolver;

class RelationshipWorkspaceResolver
{
    public function __construct(
        private readonly RelationshipDefinitionRegistry $definitions,
        private readonly SiteSettingResolver $siteSettings,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function workspaces(): array
    {
        return $this->definitions->visible();
    }

    public function defaultRelationshipKey(): ?string
    {
        $workspaces = $this->workspaces();

        if ($workspaces === []) {
            return null;
        }

        $settingKey = trim((string) config(
            'relationships.default_relationship_setting_key',
            'crm.contacts.default_relationship',
        ));

        if ($settingKey !== '') {
            $stored = $this->normalizeKey($this->siteSettings->get($settingKey));

            if ($stored !== null && isset($workspaces[$stored])) {
                return $stored;
            }
        }

        $configured = $this->normalizeKey(
            config('relationships.default_relationship'),
        );

        if ($configured !== null && isset($workspaces[$configured])) {
            return $configured;
        }

        return array_key_first($workspaces);
    }

    private function normalizeKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}