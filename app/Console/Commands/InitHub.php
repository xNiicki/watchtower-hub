<?php

namespace App\Console\Commands;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('watchtower:init')]
#[Description('First-boot provisioning: ensure the operator user and a mobile API token exist. Idempotent — safe to run on every container start.')]
class InitHub extends Command
{
    /**
     * The name given to the auto-provisioned mobile token.
     */
    private const MOBILE_TOKEN_NAME = 'mobile';

    public function handle(): int
    {
        $email = (string) config('watchtower.operator.email');

        // The operator never logs in via the web — auth is token-only — so the
        // password is a throwaway random string, just to satisfy the column.
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Operator', 'password' => Hash::make(Str::random(40))],
        );

        if ($user->tokens()->where('name', self::MOBILE_TOKEN_NAME)->exists()) {
            $this->components->info('Mobile API token already exists — leaving it untouched.');

            return self::SUCCESS;
        }

        $token = $user->createToken(self::MOBILE_TOKEN_NAME, TokenAbility::values())->plainTextToken;

        $this->newLine();
        $this->line('========================================================');
        $this->line('  MOBILE API TOKEN (shown once — copy it into the app)');
        $this->line('========================================================');
        $this->line($token);
        $this->line('========================================================');
        $this->newLine();

        return self::SUCCESS;
    }
}
