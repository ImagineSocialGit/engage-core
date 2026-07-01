<?php

namespace App\Modules\Broadcasts\Services;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use Illuminate\Database\Eloquent\Collection;

class BroadcastRecipientResolver
{
    public function __construct(
        private readonly ContactFilterResolver $contactFilterResolver,
    ) {}

    /**
     * @return Collection<int, Contact>
     */
    public function resolve(Broadcast $broadcast): Collection
    {
        return $this->contactFilterResolver->resolve($broadcast->recipient_filter ?? []);
    }
}