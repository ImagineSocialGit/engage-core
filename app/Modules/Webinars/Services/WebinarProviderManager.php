<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class WebinarProviderManager
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function provider(
        ?string $name = null,
        WebinarProviderEventType|string|null $eventType = null,
    ): WebinarProvider {
        $name = $this->providerName($name);
        $eventType = $this->eventType($eventType);
        $providerClass = $this->providerClass($name, $eventType);

        $provider = $this->container->make($providerClass);

        if (! $provider instanceof WebinarProvider) {
            throw new InvalidArgumentException(
                "Webinar provider [{$name}:{$eventType->value}] must implement ".WebinarProvider::class.'.'
            );
        }

        if (strtolower(trim($provider->key())) !== $name) {
            throw new InvalidArgumentException(
                "Webinar provider [{$name}:{$eventType->value}] resolved an adapter with mismatched key [{$provider->key()}]."
            );
        }

        return $provider;
    }

    public function forSeries(WebinarSeries $series): WebinarProvider
    {
        return $this->provider(
            name: $series->providerKey(),
            eventType: $series->providerEventTypeKey(),
        );
    }

    public function forWebinar(Webinar $webinar): WebinarProvider
    {
        return $this->provider(
            name: $webinar->providerKey(),
            eventType: $webinar->providerEventTypeKey(),
        );
    }

    /**
     * @return array<int, string>
     */
    public function configuredEventTypes(?string $name = null): array
    {
        $name = $this->providerName($name);
        $definitions = config("webinars.providers.{$name}.event_types", []);

        if (! is_array($definitions)) {
            return [];
        }

        return collect($definitions)
            ->filter(function (mixed $definition, mixed $eventType): bool {
                return WebinarProviderEventType::fromMixed($eventType) !== null
                    && is_array($definition)
                    && is_string($definition['provider'] ?? null)
                    && trim($definition['provider']) !== '';
            })
            ->keys()
            ->map(fn (mixed $eventType): string => WebinarProviderEventType::normalize($eventType))
            ->unique()
            ->values()
            ->all();
    }

    private function providerName(?string $name): string
    {
        $name ??= config('webinars.provider');

        if (! is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('No webinar provider is configured.');
        }

        return strtolower(trim($name));
    }

    private function eventType(
        WebinarProviderEventType|string|null $eventType,
    ): WebinarProviderEventType {
        $eventType ??= config('webinars.provider_event_type');
        $resolved = WebinarProviderEventType::fromMixed($eventType);

        if (! $resolved instanceof WebinarProviderEventType) {
            $supported = implode(', ', WebinarProviderEventType::values());

            throw new InvalidArgumentException(
                "Unsupported Webinar provider event type [{$this->displayValue($eventType)}]. Supported types: {$supported}."
            );
        }

        return $resolved;
    }

    /**
     * @return class-string
     */
    private function providerClass(
        string $name,
        WebinarProviderEventType $eventType,
    ): string {
        $providerClass = config(
            "webinars.providers.{$name}.event_types.{$eventType->value}.provider",
        );

        if (
            (! is_string($providerClass) || trim($providerClass) === '')
            && $eventType === WebinarProviderEventType::Webinar
        ) {
            $providerClass = config("webinars.providers.{$name}.provider");
        }

        if (! is_string($providerClass) || trim($providerClass) === '') {
            throw new InvalidArgumentException(
                "Webinar provider [{$name}] does not configure event type [{$eventType->value}]."
            );
        }

        return trim($providerClass);
    }

    private function displayValue(mixed $value): string
    {
        if ($value instanceof WebinarProviderEventType) {
            return $value->value;
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return get_debug_type($value);
    }
}