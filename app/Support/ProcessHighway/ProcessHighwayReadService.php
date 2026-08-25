<?php

namespace App\Support\ProcessHighway;

use Illuminate\Contracts\Container\Container;

final class ProcessHighwayReadService
{
    public const CONTRIBUTOR_TAG = 'process_highway.contributors';

    public function __construct(
        private readonly Container $container,
        private readonly ProcessHighwayGraphComposer $composer,
        private readonly ProcessHighwayEntryRampInspector $entryRampInspector,
    ) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        return $this->entryRampInspector->decorate(
            $this->composer->compose(
                $this->container->tagged(self::CONTRIBUTOR_TAG),
            ),
        );
    }
}