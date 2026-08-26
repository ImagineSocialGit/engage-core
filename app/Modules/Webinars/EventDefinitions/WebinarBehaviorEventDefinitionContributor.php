<?php

namespace App\Modules\Webinars\EventDefinitions;

use App\Support\Reporting\Contracts\ReportingEventDefinitionContributor;
use App\Support\Reporting\Data\ReportingEventDefinition;
use InvalidArgumentException;

final class WebinarBehaviorEventDefinitionContributor implements ReportingEventDefinitionContributor
{
    public const SURFACE = 'webinar_registration';

    public const VERSION = 1;

    /** @return iterable<int, ReportingEventDefinition> */
    public function definitions(): iterable
    {
        $browserHosts = [$this->browserHost()];
        $common = $this->commonProperties();

        yield $this->definition(
            key: 'webinar.page.view',
            properties: $common,
            browserHosts: $browserHosts,
        );

        yield $this->definition(
            key: 'webinar.cta.click',
            properties: [
                ...$common,
                'cta_location' => [
                    'type' => ReportingEventDefinition::PROPERTY_ENUM,
                    'required' => true,
                    'values' => [
                        'hero',
                        'secondary',
                        'final_close',
                        'mobile_primary',
                        'sticky_desktop',
                    ],
                ],
            ],
            browserHosts: $browserHosts,
        );

        yield $this->definition(
            key: 'webinar.modal.open',
            properties: [
                ...$common,
                'open_reason' => [
                    'type' => ReportingEventDefinition::PROPERTY_ENUM,
                    'required' => true,
                    'values' => [
                        'hero',
                        'secondary',
                        'final_close',
                        'mobile_primary',
                        'sticky_desktop',
                        'validation_return',
                        'unknown',
                    ],
                ],
            ],
            browserHosts: $browserHosts,
        );

        yield $this->definition(
            key: 'webinar.form.start',
            properties: $common,
            browserHosts: $browserHosts,
        );

        yield $this->definition(
            key: 'webinar.form.submit_attempt',
            properties: [
                ...$common,
                'bot_ready' => [
                    'type' => ReportingEventDefinition::PROPERTY_BOOLEAN,
                    'required' => true,
                ],
                'bot_interacted' => [
                    'type' => ReportingEventDefinition::PROPERTY_BOOLEAN,
                    'required' => true,
                ],
            ],
            browserHosts: $browserHosts,
        );

        yield $this->definition(
            key: 'webinar.form.validation_failed',
            properties: [
                ...$common,
                'field_keys' => [
                    'type' => ReportingEventDefinition::PROPERTY_STRING_LIST,
                    'required' => true,
                    'max_items' => 16,
                    'max_item_length' => 80,
                ],
            ],
            browserHosts: $browserHosts,
        );

        yield $this->definition(
            key: 'webinar.engagement.signal',
            properties: [
                ...$common,
                'signal' => [
                    'type' => ReportingEventDefinition::PROPERTY_ENUM,
                    'required' => true,
                    'values' => [
                        'active_10s',
                        'scroll_25',
                    ],
                ],
            ],
            browserHosts: $browserHosts,
            funnelEligible: false,
        );

        yield $this->definition(
            key: 'webinar.request.throttled',
            properties: [
                ...$common,
                'reason' => [
                    'type' => ReportingEventDefinition::PROPERTY_ENUM,
                    'required' => true,
                    'values' => [
                        'ip_minute',
                        'ip_hour',
                        'email_hour',
                        'phone_hour',
                    ],
                ],
            ],
            browserHosts: $browserHosts,
            funnelEligible: false,
        );

        yield $this->definition(
            key: 'webinar.bot_protection.result',
            properties: [
                ...$common,
                'outcome' => [
                    'type' => ReportingEventDefinition::PROPERTY_ENUM,
                    'required' => true,
                    'values' => [
                        'client_passed',
                        'client_rejected',
                        'server_rejected',
                    ],
                ],
            ],
            browserHosts: $browserHosts,
            funnelEligible: false,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function commonProperties(): array
    {
        return [
            'page_revision' => [
                'type' => ReportingEventDefinition::PROPERTY_STRING,
                'required' => true,
                'max_length' => 80,
            ],
            'presentation' => [
                'type' => ReportingEventDefinition::PROPERTY_ENUM,
                'required' => true,
                'values' => [
                    'modal',
                    'inline',
                ],
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param array<int, string> $browserHosts
     */
    private function definition(
        string $key,
        array $properties,
        array $browserHosts,
        bool $funnelEligible = true,
    ): ReportingEventDefinition {
        return new ReportingEventDefinition(
            key: $key,
            version: self::VERSION,
            surfaces: [self::SURFACE],
            sessionMode: ReportingEventDefinition::SESSION_EXPECTED,
            properties: $properties,
            funnelEligible: $funnelEligible,
            browserHosts: $browserHosts,
        );
    }

    private function browserHost(): string
    {
        $rootDomain = config('app.root_domain');

        if (! is_string($rootDomain) || trim($rootDomain) === '') {
            throw new InvalidArgumentException(
                'Webinar Reporting event definitions require app.root_domain.',
            );
        }

        $rootDomain = rtrim(strtolower(trim($rootDomain)), '.');

        return 'webinar.'.$rootDomain;
    }
}