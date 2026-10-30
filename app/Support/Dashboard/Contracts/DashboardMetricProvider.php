<?php

namespace App\Support\Dashboard\Contracts;

use Illuminate\Http\Request;

interface DashboardMetricProvider
{
    public function key(): string;

    public function module(): string;

    /**
     * Return null when the metric is not available to the current user.
     *
     * Supported payload:
     * - label: non-empty string
     * - format: count|currency
     * - value: integer count, or integer minor currency units
     * - currency: ISO 4217 code when format is currency (default USD)
     * - minor_unit_digits: 0..3 when format is currency (default 2)
     * - period: optional human-readable period label
     * - href: optional same-origin CRM URL or root-relative path
     * - priority: optional ordering preference, overridden by client config
     *
     * @return array<string, mixed>|null
     */
    public function metric(Request $request): ?array;
}