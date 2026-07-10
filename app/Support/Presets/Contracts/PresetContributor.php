<?php

namespace App\Support\Presets\Contracts;

use App\Support\Presets\Data\PresetContribution;

interface PresetContributor
{
    /**
     * @return iterable<int, PresetContribution>
     */
    public function contributions(): iterable;
}
