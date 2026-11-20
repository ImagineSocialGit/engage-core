<?php

namespace App\Support\Users;

use Illuminate\Validation\Rules\Password;

/** Enforced for new CRM credentials and password resets, not existing hashes. */
final class CrmPasswordPolicy
{
    public static function rule(): Password
    {
        return Password::min(12)->mixedCase()->numbers()->symbols();
    }
}
