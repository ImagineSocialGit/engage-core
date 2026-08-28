<?php

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Messaging\Contracts\MessageTemplatePublicationHook;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use InvalidArgumentException;

final class MessageTemplatePublicationHookRegistry
{
    /** @var array<int, MessageTemplatePublicationHook> */
    private array $hooks;

    public function __construct(iterable $hooks = [])
    {
        $this->hooks = [];

        foreach ($hooks as $hook) {
            if (! $hook instanceof MessageTemplatePublicationHook) {
                throw new InvalidArgumentException(sprintf(
                    'Message template publication hook [%s] must implement [%s].',
                    get_debug_type($hook),
                    MessageTemplatePublicationHook::class,
                ));
            }

            $this->hooks[] = $hook;
        }
    }

    public function afterPublish(
        MessageTemplatePreset $preset,
        MessageTemplateVersion $version,
        ?User $actor = null,
    ): void {
        foreach ($this->hooks as $hook) {
            $hook->afterPublish($preset, $version, $actor);
        }
    }
}