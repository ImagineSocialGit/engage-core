<?php

namespace App\Console\Commands;

use App\Support\Users\CrmUserManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EngageUserPasswordCommand extends Command
{
    protected $signature = 'engage:user:password
        {email? : Email address of the CRM user whose password should be reset}';

    protected $description = 'Reset a CRM user password through hidden terminal input.';

    public function handle(CrmUserManager $users): int
    {
        if (! Schema::hasTable('users')) {
            $this->error(
                'The users table does not exist. Run engage:install or the platform migrations before resetting CRM passwords.',
            );

            return self::FAILURE;
        }

        $email = trim((string) (
            $this->argument('email')
            ?: $this->ask('Email')
        ));

        $password = (string) $this->secret('New password');
        $confirmation = (string) $this->secret('Confirm new password');

        if ($password !== $confirmation) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        try {
            $user = $users->resetPassword(
                email: $email,
                password: $password,
            );
        } catch (ValidationException $exception) {
            return $this->renderValidationFailure($exception);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Password reset for CRM user [{$user->email}].");

        return self::SUCCESS;
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