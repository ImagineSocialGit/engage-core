<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CrmUserCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_add_creates_a_platform_login_without_creating_a_team_member(): void
    {
        $this->artisan('engage:user:add')
            ->expectsQuestion('Name', 'First Admin')
            ->expectsQuestion('Email', 'FIRST.ADMIN@example.com')
            ->expectsQuestion('Password', 'Strong-Test-Password-42!')
            ->expectsQuestion('Confirm password', 'Strong-Test-Password-42!')
            ->assertExitCode(0);

        $user = User::query()
            ->where('email', 'first.admin@example.com')
            ->sole();

        $this->assertSame('First Admin', $user->name);
        $this->assertTrue(Hash::check(
            'Strong-Test-Password-42!',
            $user->password,
        ));
        $this->assertTrue(Schema::hasTable('team_members'));
        $this->assertDatabaseCount('team_members', 0);
    }

    public function test_user_add_rejects_an_existing_email_without_changing_the_existing_password(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'password' => 'existing-password',
        ]);

        $existingPasswordHash = $user->password;

        $this->artisan('engage:user:add')
            ->expectsQuestion('Name', 'Replacement Admin')
            ->expectsQuestion('Email', 'existing@example.com')
            ->expectsQuestion('Password', 'Replacement-Password-42!')
            ->expectsQuestion('Confirm password', 'Replacement-Password-42!')
            ->assertExitCode(1);

        $user->refresh();

        $this->assertSame($existingPasswordHash, $user->password);
        $this->assertTrue(Hash::check(
            'existing-password',
            $user->password,
        ));
    }

    public function test_user_password_resets_only_the_requested_users_password(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'old-password',
        ]);

        $otherUser = User::factory()->create([
            'email' => 'other@example.com',
            'password' => 'other-password',
        ]);

        $otherPasswordHash = $otherUser->password;

        $this->artisan('engage:user:password', [
            'email' => 'owner@example.com',
        ])
            ->expectsQuestion('New password', 'New-Owner-Password-42!')
            ->expectsQuestion('Confirm new password', 'New-Owner-Password-42!')
            ->assertExitCode(0);

        $user->refresh();
        $otherUser->refresh();

        $this->assertTrue(Hash::check(
            'New-Owner-Password-42!',
            $user->password,
        ));
        $this->assertSame(
            $otherPasswordHash,
            $otherUser->password,
        );
    }

    public function test_user_password_rejects_an_unknown_email(): void
    {
        $this->artisan('engage:user:password', [
            'email' => 'missing@example.com',
        ])
            ->expectsQuestion('New password', 'New-Owner-Password-42!')
            ->expectsQuestion('Confirm new password', 'New-Owner-Password-42!')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }
}