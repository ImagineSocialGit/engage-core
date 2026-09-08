<?php

namespace App\View\Components\Layouts;

use App\Support\Modules\ModuleManager;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class Crm extends Component
{
    /**
     * @var array<int, array{
     *     module: string,
     *     label: string,
     *     description: string,
     *     route: string,
     *     href: string,
     *     priority: int,
     *     class: string
     * }>
     */
    public array $navigationItems;

    public string $navBaseClass;

    public string $mainSurfaceClass;

    public function __construct(
        public ?string $title = null,
        public ?string $heading = null,
        public ?string $subheading = null,
        public ?string $metaDescription = null,
        public ?string $module = null,
    ) {
        $this->navigationItems = app(ModuleManager::class)->navigationItems();
        $this->navBaseClass = 'block rounded-lg px-3 py-2 font-medium text-slate-700 transition focus-visible:outline-none focus-visible:ring-2';
        $this->mainSurfaceClass = is_string($module) && trim($module) !== ''
            ? module_tone($module, 'panel')
            : 'bg-slate-50';
    }

    public function render(): View
    {
        return view('components.layouts.crm');
    }
}