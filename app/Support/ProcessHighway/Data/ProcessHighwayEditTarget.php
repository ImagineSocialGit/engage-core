<?php

namespace App\Support\ProcessHighway\Data;

final readonly class ProcessHighwayEditTarget
{
    public const MODE_LINK = 'link';

    public const MODE_INLINE = 'inline';

    public string $method;

    public function __construct(
        public string $mode,
        public string $ownerKey,
        public string $label,
        public string $url,
        public string $resourceType,
        public string $resourceKey,
        public int|string|null $resourceId = null,
        public ?string $capability = null,
        string $method = 'GET',
        public ?string $containerType = null,
        public ?string $containerKey = null,
        public int|string|null $containerId = null,
    ) {
        $this->method = strtoupper(trim($method));
    }

    public static function link(
        string $ownerKey,
        string $label,
        string $url,
        string $resourceType,
        string $resourceKey,
        int|string|null $resourceId = null,
        ?string $containerType = null,
        ?string $containerKey = null,
        int|string|null $containerId = null,
    ): self {
        return new self(
            mode: self::MODE_LINK,
            ownerKey: $ownerKey,
            label: $label,
            url: $url,
            resourceType: $resourceType,
            resourceKey: $resourceKey,
            resourceId: $resourceId,
            method: 'GET',
            containerType: $containerType,
            containerKey: $containerKey,
            containerId: $containerId,
        );
    }

    public static function inline(
        string $ownerKey,
        string $label,
        string $url,
        string $method,
        string $capability,
        string $resourceType,
        string $resourceKey,
        int|string|null $resourceId = null,
        ?string $containerType = null,
        ?string $containerKey = null,
        int|string|null $containerId = null,
    ): self {
        return new self(
            mode: self::MODE_INLINE,
            ownerKey: $ownerKey,
            label: $label,
            url: $url,
            resourceType: $resourceType,
            resourceKey: $resourceKey,
            resourceId: $resourceId,
            capability: $capability,
            method: $method,
            containerType: $containerType,
            containerKey: $containerKey,
            containerId: $containerId,
        );
    }

    public function identity(): string
    {
        return implode('|', [
            $this->mode,
            $this->ownerKey,
            $this->method,
            $this->url,
            $this->capability ?? '',
            $this->resourceType,
            $this->resourceKey,
            (string) ($this->resourceId ?? ''),
            $this->containerType ?? '',
            $this->containerKey ?? '',
            (string) ($this->containerId ?? ''),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'owner_key' => $this->ownerKey,
            'owner_label' => (string) config(
                "modules.modules.{$this->ownerKey}.name",
                str($this->ownerKey)->headline()->toString(),
            ),
            'label' => $this->label,
            'url' => $this->url,
            'method' => $this->method,
            'capability' => $this->capability,
            'resource' => [
                'type' => $this->resourceType,
                'key' => $this->resourceKey,
                'id' => $this->resourceId,
            ],
            'container' => $this->containerType !== null
                ? [
                    'type' => $this->containerType,
                    'key' => $this->containerKey,
                    'id' => $this->containerId,
                ]
                : null,
        ];
    }
}