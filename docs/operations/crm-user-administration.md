# Engage Core — CRM User Administration

## Purpose

CRM login users are platform authentication records.

They are not preset data, client environment configuration, or Internal Notifications TeamMembers.

Use the user-administration commands for the first CRM login created during client kickoff and for additional CRM users added later.

## Create a CRM user

Run:

```bash
php artisan engage:user:add
```

The command prompts for:

```text
name
email
password
password confirmation
```

Password input is hidden.

Name and email may be supplied as non-secret options when useful:

```bash
php artisan engage:user:add \
  --name="Example User" \
  --email="user@example.com"
```

The password is intentionally not accepted as a command-line option because command-line arguments may be retained in shell history or process inspection.

The command:

- requires the platform `users` table to exist;
- normalizes the email address to lowercase;
- validates the current Laravel password-policy defaults;
- refuses to overwrite an existing user with the same email;
- relies on the User model's hashed password cast;
- does not create or update an Internal Notifications TeamMember.

## Initial user during engage:install

A normal interactive new-client install asks after the four installation stages:

```text
Create a CRM user now? [Y/n]
```

The schema/module/preset/setup-validation stages remain unchanged.

Available controls:

```text
--create-user
    require CRM user creation after successful installation and skip the Y/N question

--no-create-user
    skip CRM user creation and print the after-the-fact command
```

The two options are mutually exclusive.

`--create-user` requires an interactive terminal because the password is intentionally collected through hidden input.

For non-interactive deployment automation, use:

```bash
php artisan engage:install --force --no-create-user
```

Then create the intended user from an interactive operator session:

```bash
php artisan engage:user:add
```

Do not pass passwords through deployment scripts, environment files, command-line arguments, or source-controlled configuration.

## Reset a forgotten password

Run:

```bash
php artisan engage:user:password user@example.com
```

Or omit the email to be prompted:

```bash
php artisan engage:user:password
```

The new password and confirmation are entered through hidden terminal prompts.

Password reset is explicit. Creating an already-existing user does not silently change that user's password.

## Environment contract

The following legacy bootstrap variables are no longer supported:

```text
SETUP_USER_NAME
SETUP_USER_EMAIL
SETUP_USER_PASSWORD
```

Do not keep CRM login passwords in `.env`.

Store operator credentials in the approved password manager. If a password is unavailable, reset it through `engage:user:password`.

## Related identities are separate

These concepts are intentionally independent:

```text
CRM User
    application authentication identity in users

TeamMember
    optional Internal Notifications recipient/profile

STAGING_USER / STAGING_PASSWORD
    staging HTTP/access gate when configured

PROJECT_STATE_ADMIN_EMAIL
    exact CRM user email authorized for the owner-only Project State surface
```

Creating a CRM User does not imply that the person should receive Internal Notifications.

If a client uses Internal Notifications, create and manage TeamMember state through that module's own workflow.

`PROJECT_STATE_ADMIN_EMAIL` remains an environment-owned authorization setting because it selects which existing CRM login may operate the Project State maintenance surface. It is not a password.

## Database seeders

`DatabaseSeeder` no longer provisions operational CRM users.

Do not use `db:seed` as a client-user bootstrap mechanism.

The retired `UserSeeder` and `config/setup.php` should not exist after this cutover.