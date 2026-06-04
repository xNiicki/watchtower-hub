<?php

namespace App\Console\Commands;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\MultipleRecordsFoundException;

#[Signature('watchtower:token {name}')]
#[Description('Create a watchtower token')]
class MintMobileToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $user = User::sole();
        } catch (ModelNotFoundException) {
            $this->error('No user found. Please create a user first.');

            return self::FAILURE;
        } catch (MultipleRecordsFoundException) {
            $this->error('Multiple users found. Please ensure there is only one user in the database.');

            return self::FAILURE;
        }

        $token = $user->createToken($this->argument('name'), TokenAbility::mobile())->plainTextToken;

        $this->line($token);

        return self::SUCCESS;
    }
}
