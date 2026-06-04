<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;

#[Signature('watchtower:admin {email? : Operator email (defaults to config watchtower.operator.email)} {--password= : Set the password non-interactively (prefer the interactive prompt to keep it out of shell history)}')]
#[Description('Set (or reset) the admin password for the operator so they can log into the /admin web panel. watchtower:init provisions the operator with a throwaway random password; run this once on first boot to choose a real one.')]
class SetAdminPassword extends Command
{
    /**
     * The minimum acceptable admin password length.
     */
    private const MINIMUM_PASSWORD_LENGTH = 8;

    public function handle(): int
    {
        $email = (string) ($this->argument('email') ?? config('watchtower.operator.email'));

        $user = User::firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;

        $password = (string) ($this->option('password') ?? password(
            label: "Set the admin password for {$email}",
            required: true,
            validate: fn (string $value) => strlen($value) < self::MINIMUM_PASSWORD_LENGTH
                ? 'The password must be at least '.self::MINIMUM_PASSWORD_LENGTH.' characters.'
                : null,
        ));

        if (strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            $this->components->error('The password must be at least '.self::MINIMUM_PASSWORD_LENGTH.' characters.');

            return self::FAILURE;
        }

        if ($isNew) {
            $user->name = 'Operator';
        }

        $user->password = Hash::make($password);
        $user->save();

        $this->components->info(
            $isNew
                ? "Created operator [{$email}] and set the admin password."
                : "Updated the admin password for [{$email}].",
        );
        $this->line('You can now log in to the web panel at /admin.');

        return self::SUCCESS;
    }
}
