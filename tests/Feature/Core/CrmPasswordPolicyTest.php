<?php

namespace Tests\Feature\Core;

use App\Support\Users\CrmUserManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CrmPasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_weak_password_is_rejected_for_new_staff(): void
    {
        $this->expectException(ValidationException::class);

        app(CrmUserManager::class)->create(
            name: 'Staff',
            email: 'staff@example.test',
            password: 'password123',
            roleKey: 'member',
        );
    }

    public function test_strong_password_is_accepted_for_new_staff(): void
    {
        $user = app(CrmUserManager::class)->create(
            name: 'Staff',
            email: 'staff@example.test',
            password: 'Example-Staff-Password-2026!',
            roleKey: 'member',
        );

        $this->assertNotNull($user->getKey());
    }
}
