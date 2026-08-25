<?php

namespace App\Modules\InboundMessaging\Actions\ReplyProfiles;

use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Support\ReplyHandling\ReplyProfileDependencyRegistry;
use Illuminate\Validation\ValidationException;

final class DeleteInboundReplyProfileAction
{
    public function __construct(
        private readonly ReplyProfileDependencyRegistry $dependencies,
    ) {}

    public function handle(InboundReplyProfile $profile): void
    {
        if ($this->dependencies->profileIsBlocked($profile->key)) {
            throw ValidationException::withMessages([
                'profile' => 'This reply profile is still referenced and cannot be removed.',
            ]);
        }

        $profile->delete();
    }
}