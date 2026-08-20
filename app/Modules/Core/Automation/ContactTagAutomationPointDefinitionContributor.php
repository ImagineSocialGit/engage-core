<?php

namespace App\Modules\Core\Automation;

use App\Support\AutomationCapabilities\Contracts\AutomationPointDefinitionContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class ContactTagAutomationPointDefinitionContributor implements AutomationPointDefinitionContributor
{
    private const POINT_TYPES = [
        'add_contact_tag',
        'remove_contact_tag',
    ];

    private const MAX_TAG_LENGTH = 255;

    public function definitions(): iterable
    {
        foreach (self::POINT_TYPES as $pointType) {
            yield new AutomationPointDefinition(
                pointType: $pointType,
                schema: ConfigSchema::object([
                    'tag' => ConfigField::required(ConfigSchema::string()),
                ]),
            );
        }
    }

    public function validate(
        string $pointType,
        array $definition,
        array $settings,
        AutomationPointValidationContext $context,
    ): iterable {
        if (! in_array($pointType, self::POINT_TYPES, true)) {
            return;
        }

        $tag = trim((string) (array_replace_recursive($definition, $settings)['tag'] ?? ''));

        if ($tag === '') {
            yield $context->error(
                code: 'flow_routes.contact_tag_missing',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] requires a non-empty Contact tag.",
                path: "{$context->path}.definition.tag",
                context: [
                    'point_key' => $context->pointKey,
                    'point_type' => $pointType,
                ],
            );

            return;
        }

        if (mb_strlen($tag) > self::MAX_TAG_LENGTH) {
            yield $context->error(
                code: 'flow_routes.contact_tag_too_long',
                message: "FlowRoute [{$context->containerKey}] point [{$context->pointKey}] Contact tag may not exceed ".self::MAX_TAG_LENGTH.' characters.',
                path: "{$context->path}.definition.tag",
                context: [
                    'point_key' => $context->pointKey,
                    'point_type' => $pointType,
                    'maximum_length' => self::MAX_TAG_LENGTH,
                ],
            );
        }
    }
}