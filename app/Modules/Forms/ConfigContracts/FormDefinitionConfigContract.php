<?php

namespace App\Modules\Forms\ConfigContracts;

use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Services\FormSchemaNormalizer;
use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

final class FormDefinitionConfigContract implements ConfigContract
{
    public function key(): string
    {
        return 'forms.form_definition';
    }

    public function owner(): string
    {
        return 'forms';
    }

    public function sourcePattern(): string
    {
        return 'presets.modules.{contributor}.forms.definitions.{form_key}';
    }

    public function schema(): ConfigSchema
    {
        $option = ConfigSchema::object([
            'value' => ConfigField::required(ConfigSchema::string()),
            'label' => ConfigField::required(ConfigSchema::string()),
        ], allowUnknown: true);

        $field = ConfigSchema::object([
            'key' => ConfigField::required(ConfigSchema::string()),
            'label' => ConfigField::required(ConfigSchema::string()),
            'type' => ConfigField::required(ConfigSchema::string(
                allowedValues: FormSchemaNormalizer::FIELD_TYPES,
            )),
            'required' => ConfigField::defaulted(ConfigSchema::boolean(), false),
            'options' => ConfigField::optional(ConfigSchema::listOf($option)),
        ], allowUnknown: true);

        $section = ConfigSchema::object([
            'key' => ConfigField::required(ConfigSchema::string()),
            'label' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'fields' => ConfigField::required(ConfigSchema::listOf($field)),
        ], allowUnknown: true);

        $arrayValue = ConfigSchema::oneOf([
            ConfigSchema::object([], allowUnknown: true),
            ConfigSchema::listOf(ConfigSchema::mixed()),
        ]);

        return ConfigSchema::object([
            'key' => ConfigField::required(ConfigSchema::string()),
            'name' => ConfigField::required(ConfigSchema::string()),
            'description' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            'category' => ConfigField::defaulted(
                ConfigSchema::string(allowedValues: [
                    FormDefinition::CATEGORY_INTAKE,
                    FormDefinition::CATEGORY_QUESTIONNAIRE,
                    FormDefinition::CATEGORY_REVIEW,
                    FormDefinition::CATEGORY_REQUEST,
                    FormDefinition::CATEGORY_FEEDBACK,
                ]),
                FormDefinition::CATEGORY_INTAKE,
            ),
            'is_public' => ConfigField::defaulted(ConfigSchema::boolean(), false),
            'schema' => ConfigField::required(ConfigSchema::object([
                'sections' => ConfigField::required(ConfigSchema::listOf($section)),
            ], allowUnknown: true)),
            'rules' => ConfigField::defaulted($arrayValue, []),
            'layout' => ConfigField::defaulted($arrayValue, []),
            'settings' => ConfigField::defaulted($arrayValue, []),
            'meta' => ConfigField::defaulted(
                ConfigSchema::object([], allowUnknown: true),
                [],
            ),
        ]);
    }

    public function example(): array
    {
        return [
            'key' => 'artist_updates',
            'name' => 'Artist Updates',
            'description' => 'Reusable contact update intake.',
            'category' => FormDefinition::CATEGORY_INTAKE,
            'is_public' => true,
            'schema' => [
                'sections' => [[
                    'key' => 'contact',
                    'label' => 'Contact',
                    'fields' => [[
                        'key' => 'email',
                        'label' => 'Email',
                        'type' => 'email',
                        'required' => true,
                    ]],
                ]],
            ],
            'rules' => [],
            'layout' => [],
            'settings' => [],
            'meta' => [],
        ];
    }
}