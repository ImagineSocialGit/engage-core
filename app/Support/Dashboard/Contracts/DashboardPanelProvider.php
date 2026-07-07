<?php

namespace App\Support\Dashboard\Contracts;

use Illuminate\Http\Request;

interface DashboardPanelProvider
{
    public function key(): string;

    public function module(): string;

    /**
     * @return array<string, mixed>|null
     */
    public function panel(Request $request): ?array;
}
