<?php

namespace App\Modules\Tasks\Data;

use Illuminate\Database\Eloquent\Model;

class TaskRecipient
{
    public function __construct(
        public readonly Model $source,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?Model $preferenceOwner = null,
    ) {}
}
