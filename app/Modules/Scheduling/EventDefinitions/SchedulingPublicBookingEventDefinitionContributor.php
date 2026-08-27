<?php

namespace App\Modules\Scheduling\EventDefinitions;

use App\Support\Reporting\Contracts\ReportingEventDefinitionContributor;
use App\Support\Reporting\Data\ReportingEventDefinition;

final class SchedulingPublicBookingEventDefinitionContributor implements ReportingEventDefinitionContributor
{
    public const SURFACE = 'scheduling_public_booking';

    public const VERSION = 1;

    /** @return iterable<int, ReportingEventDefinition> */
    public function definitions(): iterable
    {
        $browserHost = $this->browserHost();

        if ($browserHost === null) {
            return;
        }

        $browserHosts = [$browserHost];
        $common = $this->commonProperties();

        yield $this->definition('scheduling.booking.page_view', $common, $browserHosts);

        yield $this->definition('scheduling.booking.service_selected', $common, $browserHosts);

        yield $this->definition(
            'scheduling.booking.availability_viewed',
            [
                ...$common,
                'availability_state' => [
                    'type' => ReportingEventDefinition::PROPERTY_ENUM,
                    'required' => true,
                    'values' => ['available', 'empty', 'address_required', 'range'],
                ],
            ],
            $browserHosts,
        );

        yield $this->definition(
            'scheduling.booking.time_selected',
            [
                ...$common,
                'day_period' => [
                    'type' => ReportingEventDefinition::PROPERTY_ENUM,
                    'required' => true,
                    'values' => ['morning', 'afternoon', 'evening', 'range'],
                ],
            ],
            $browserHosts,
        );

        yield $this->definition(
            'scheduling.booking.verification_requested',
            [...$common, 'channel' => $this->channelProperty()],
            $browserHosts,
        );

        yield $this->definition(
            'scheduling.booking.verification_completed',
            [...$common, 'channel' => $this->channelProperty()],
            $browserHosts,
        );

        yield $this->definition('scheduling.booking.details_started', $common, $browserHosts);

        yield $this->definition('scheduling.booking.submit_attempt', $common, $browserHosts);

        yield $this->definition(
            'scheduling.booking.validation_failed',
            [
                ...$common,
                'field_keys' => [
                    'type' => ReportingEventDefinition::PROPERTY_STRING_LIST,
                    'required' => true,
                    'max_items' => 16,
                    'max_item_length' => 80,
                ],
            ],
            $browserHosts,
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function commonProperties(): array
    {
        return [
            'page_revision' => [
                'type' => ReportingEventDefinition::PROPERTY_STRING,
                'required' => true,
                'max_length' => 80,
            ],
            'state' => [
                'type' => ReportingEventDefinition::PROPERTY_ENUM,
                'required' => true,
                'values' => [
                    'catalog',
                    'address',
                    'availability',
                    'offer',
                    'verification',
                    'details',
                    'confirmation',
                    'expired',
                ],
            ],
            'service_key' => [
                'type' => ReportingEventDefinition::PROPERTY_STRING,
                'required' => false,
                'max_length' => 100,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function channelProperty(): array
    {
        return [
            'type' => ReportingEventDefinition::PROPERTY_ENUM,
            'required' => true,
            'values' => ['email', 'sms'],
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
    ): ReportingEventDefinition {
        return new ReportingEventDefinition(
            key: $key,
            version: self::VERSION,
            surfaces: [self::SURFACE],
            sessionMode: ReportingEventDefinition::SESSION_EXPECTED,
            properties: $properties,
            funnelEligible: true,
            browserHosts: $browserHosts,
        );
    }

    private function browserHost(): ?string
    {
        $host = config('scheduling.public.host');

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return rtrim(strtolower(trim($host)), '.');
    }
}