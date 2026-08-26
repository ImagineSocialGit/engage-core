<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Modules\ModuleManager;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __invoke(ModuleManager $modules): View
    {
        $items = collect($modules->settingsItems());
        $groups = collect($modules->settingsCategories())
            ->map(function (array $category) use ($items): array {
                return [
                    ...$category,
                    'items' => $items
                        ->where('category', $category['key'])
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $group): bool => $group['items'] !== [])
            ->values()
            ->all();

        return view('crm.settings.index', [
            'settingsGroups' => $groups,
            'gettingStarted' => $modules->gettingStartedItems(),
        ]);
    }
}