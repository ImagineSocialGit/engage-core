<?php

namespace App\Modules\InboundMessaging\Actions\ReplyProfiles;

use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Support\ReplyHandling\ReplyProfileDependencyRegistry;
use Illuminate\Validation\ValidationException;

final class SetInboundReplyProfileStateAction
{
    public function __construct(
        private readonly ReplyProfileDependencyRegistry $dependencies,
    ) {}

    public function handle(InboundReplyProfile $profile, bool $active): InboundReplyProfile
    {
        if (! $active
            && $profile->is_active
            && $this->dependencies->profileIsBlocked($profile->key)
        ) {
            throw ValidationException::withMessages([
                'profile' => 'This reply profile is still referenced. Remove those dependencies before disabling it.',
            ]);
        }

        $profile->forceFill([
            'is_active' => $active,
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        return $profile->refresh();
    }
}