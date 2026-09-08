<?php

namespace App\Modules\Core\Jobs;

use App\Models\User;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class AddTagToContactResultChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param array<int, int> $contactIds
     */
    public function __construct(
        public readonly array $contactIds,
        public readonly string $tag,
        public readonly int $actorUserId,
    ) {}

    public function handle(
        UserAccessService $access,
        ContactVisibility $visibility,
    ): void {
        $actor = User::query()->find($this->actorUserId);

        if (! $actor instanceof User || ! $access->allows($actor, 'contacts.manage')) {
            return;
        }

        $contacts = $visibility
            ->apply(
                Contact::query()->whereIn('contacts.id', $this->contactIds),
                $actor,
            )
            ->reorder()
            ->orderBy('contacts.id')
            ->get(['contacts.id']);

        foreach ($contacts as $contact) {
            ContactTag::query()->firstOrCreate([
                'contact_id' => $contact->getKey(),
                'tag' => $this->tag,
            ]);
        }
    }
}