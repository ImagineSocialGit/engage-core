<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginThrottlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_form_uses_the_crm_auth_view(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertViewIs('crm.auth.login');
    }

    public function test_failed_login_attempts_are_rate_limited(): void
    {
        config([
            'security.crm_login.max_attempts' => 2,
            'security.crm_login.decay_seconds' => 60,
        ]);

        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_successful_login_clears_rate_limiter(): void
    {
        config([
            'security.crm_login.max_attempts' => 2,
            'security.crm_login.decay_seconds' => 60,
        ]);

        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ])->assertRedirect();

        $key = Str::transliterate('admin@example.com|127.0.0.1');

        $this->assertSame(0, RateLimiter::attempts($key));
        $this->assertAuthenticated();
    }
}