<?php

namespace App\Console\Commands;

use App\Support\Users\CrmUserManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EngageUserAddCommand extends Command
{
    protected $signature = 'engage:user:add
        {--name= : CRM user display name; prompts when omitted}
        {--email= : CRM user email; prompts when omitted}';

    protected $description = 'Create a CRM login user without depending on optional modules.';

    public function handle(CrmUserManager $users): int
    {
        if (! Schema::hasTable('users')) {
            $this->error(
                'The users table does not exist. Run engage:install or the platform migrations before creating CRM users.',
            );

            return self::FAILURE;
        }

        $name = $this->stringOption('name')
            ?? trim((string) $this->ask('Name'));

        $email = $this->stringOption('email')
            ?? trim((string) $this->ask('Email'));

        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        try {
            $user = $users->create(
                name: $name,
                email: $email,
                password: $password,
            );
        } catch (ValidationException $exception) {
            return $this->renderValidationFailure($exception);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("CRM user [{$user->email}] created.");

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function renderValidationFailure(
        ValidationException $exception,
    ): int {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                $this->error($message);
            }
        }

        return self::FAILURE;
    }
}