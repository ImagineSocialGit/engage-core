<?php

namespace App\Modules\Core\Support\Contacts;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Contracts\Contacts\ContactPanelProvider;
use App\Modules\Core\Data\Contacts\ContactPanel;
use App\Support\Modules\ModuleManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

class ContactPanelRegistry
{
    protected array $providers = [];

    public function __construct(
        protected Container $container,
        protected ModuleManager $modules,
    ) {
    }

    public function register(string|ContactPanelProvider $provider, ?string $module = null): self
    {
        $key = is_string($provider) ? $provider : $provider::class;

        $this->providers[$key] = [
            'provider' => $provider,
            'module' => $module,
        ];

        return $this;
    }

    public function panelsFor(Contact $contact): Collection
    {
        return collect($this->providers)
            ->filter(fn (array $entry) => $entry['module'] === null || $this->modules->enabled($entry['module']))
            ->flatMap(fn (array $entry) => $this->resolveProvider($entry['provider'])->panels($contact))
            ->filter(fn (mixed $panel) => $panel instanceof ContactPanel)
            ->sortBy(fn (ContactPanel $panel) => sprintf('%010d-%s', $panel->sort, $panel->key))
            ->values();
    }

    protected function resolveProvider(string|ContactPanelProvider $provider): ContactPanelProvider
    {
        if ($provider instanceof ContactPanelProvider) {
            return $provider;
        }

        return $this->container->make($provider);
    }
}