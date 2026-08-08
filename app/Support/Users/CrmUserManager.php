<?php

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class CrmUserManager
{
    /**
     * @throws ValidationException
     */
    public function create(
        string $name,
        string $email,
        string $password,
    ): User {
        $attributes = [
            'name' => trim($name),
            'email' => $this->normalizeEmail($email),
            'password' => $password,
        ];

        $validated = Validator::make($attributes, [
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => [
                'required',
                'string',
                Password::defaults(),
            ],
        ])->validate();

        return User::query()->create($validated);
    }

    /**
     * @throws ValidationException
     */
    public function resetPassword(
        string $email,
        string $password,
    ): User {
        $attributes = [
            'email' => $this->normalizeEmail($email),
            'password' => $password,
        ];

        $validated = Validator::make($attributes, [
            'email' => [
                'required',
                'email',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
                Password::defaults(),
            ],
        ])->validate();

        $user = User::query()
            ->where('email', $validated['email'])
            ->first();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'email' => sprintf(
                    'No CRM user exists with email [%s].',
                    $validated['email'],
                ),
            ]);
        }

        $user->forceFill([
            'password' => $validated['password'],
        ])->save();

        return $user->refresh();
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}