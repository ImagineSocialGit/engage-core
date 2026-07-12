<?php

namespace App\Modules\Messaging\ConfigContracts;

use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;

class PermissionInvitationConfigContract implements ConfigContract
{
    public function key(): string
    {
        return 'messaging.permission_invitation';
    }

    public function owner(): string
    {
        return 'messaging';
    }

    public function sourcePattern(): string
    {
        return 'messaging.permission_invitations';
    }

    public function schema(): ConfigSchema
    {
        $copy = fn (): ConfigSchema => ConfigSchema::string(nullable: true);
        $option = fn (): ConfigSchema => ConfigSchema::object([
            'label' => ConfigField::optional($copy()),
            'body' => ConfigField::optional($copy()),
        ]);

        return ConfigSchema::object([
            'public' => ConfigField::optional(ConfigSchema::object([
                'base_url' => ConfigField::optional(ConfigSchema::string(nullable: true)),
            ])),
            'email' => ConfigField::optional(ConfigSchema::object([
                'subject' => ConfigField::optional($copy()),
                'body' => ConfigField::optional($copy()),
                'cta_label' => ConfigField::optional($copy()),
                'secondary_link_label' => ConfigField::optional($copy()),
            ])),
            'consent' => ConfigField::optional(ConfigSchema::object([
                'scopes' => ConfigField::optional(ConfigSchema::listOf(ConfigSchema::string())),
            ])),
            'content' => ConfigField::optional(ConfigSchema::object([
                'title' => ConfigField::optional($copy()),
                'meta_description' => ConfigField::optional($copy()),
                'eyebrow' => ConfigField::optional($copy()),
                'heading' => ConfigField::optional($copy()),
                'body' => ConfigField::optional($copy()),
                'options' => ConfigField::optional(ConfigSchema::object([
                    'email' => ConfigField::optional($option()),
                    'sms' => ConfigField::optional($option()),
                ])),
                'phone_label' => ConfigField::optional($copy()),
                'phone_help' => ConfigField::optional($copy()),
                'submit_label' => ConfigField::optional($copy()),
                'legal' => ConfigField::optional($copy()),
                'accepted_title' => ConfigField::optional($copy()),
                'accepted_heading' => ConfigField::optional($copy()),
                'accepted_body' => ConfigField::optional($copy()),
            ])),
            'style' => ConfigField::optional(ConfigSchema::object([
                'section' => ConfigField::optional($copy()),
                'inner' => ConfigField::optional($copy()),
                'card' => ConfigField::optional($copy()),
                'eyebrow' => ConfigField::optional($copy()),
                'heading' => ConfigField::optional($copy()),
                'body' => ConfigField::optional($copy()),
                'option' => ConfigField::optional($copy()),
                'option_label' => ConfigField::optional($copy()),
                'option_body' => ConfigField::optional($copy()),
                'button' => ConfigField::optional($copy()),
                'legal' => ConfigField::optional($copy()),
            ])),
        ]);
    }

    public function example(): array
    {
        return [
            'email' => [
                'subject' => 'Confirm how you want to hear from us',
                'body' => 'Hi {first_name}, please confirm your preferences.',
                'cta_label' => 'Confirm my preferences',
            ],
            'consent' => [
                'scopes' => ['broadcast', 'campaign'],
            ],
        ];
    }
}
