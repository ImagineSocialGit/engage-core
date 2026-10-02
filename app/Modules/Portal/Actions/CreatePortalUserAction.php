<?php

namespace App\Modules\Portal\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Portal\Models\PortalContactLink;
use App\Modules\Portal\Models\PortalUser;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreatePortalUserAction
{
    public function __construct(
        private readonly LinkPortalUserToContactAction $links,
    ) {}

    public function handle(
        string $name,
        string $email,
        string $password,
        ?string $phone = null,
        ?Contact $contact = null,
        string $relationship = PortalContactLink::RELATIONSHIP_SELF,
        bool $emailVerified = false,
        string $source = 'self_registration',
    ): PortalUser {
        $name = $this->name($name);
        $email = PortalUser::canonicalEmail($email);
        $password = $this->password($password);
        $phone = $this->phone($phone);
        $source = $this->source($source);

        return DB::transaction(function () use (
            $name,
            $email,
            $password,
            $phone,
            $contact,
            $relationship,
            $emailVerified,
            $source,
        ): PortalUser {
            if (PortalUser::withTrashed()
                ->where('email', $email)
                ->lockForUpdate()
                ->exists()
            ) {
                throw new DomainException(
                    'A Portal account already exists for this email address.',
                );
            }

            $now = now();

            $user = PortalUser::query()->create([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => $password,
                'status' => PortalUser::STATUS_ACTIVE,
                'email_verified_at' => $emailVerified ? $now : null,
                'accepted_at' => $now,
                'source' => $source,
            ]);

            if ($contact instanceof Contact) {
                $this->links->handle(
                    portalUser: $user,
                    contact: $contact,
                    relationship: $relationship,
                    isPrimary: true,
                    verified: true,
                    source: $source,
                );
            }

            return $user->refresh();
        });
    }

    private function name(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('Portal account name is invalid.');
        }

        return $name;
    }

    private function password(string $password): string
    {
        if (mb_strlen($password) < 8 || mb_strlen($password) > 255) {
            throw new InvalidArgumentException(
                'Portal account password must be between 8 and 255 characters.',
            );
        }

        return $password;
    }

    private function phone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $phone = trim($phone);

        if (mb_strlen($phone) > 100) {
            throw new InvalidArgumentException('Portal account phone is invalid.');
        }

        return $phone;
    }

    private function source(string $source): string
    {
        $source = trim($source);

        if ($source === '' || mb_strlen($source) > 100) {
            throw new InvalidArgumentException('Portal account source is invalid.');
        }

        return $source;
    }
}