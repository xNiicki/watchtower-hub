<?php

namespace Database\Factories;

use App\Models\SyslogEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyslogEntry>
 */
class SyslogEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $loggedAt = now()->subMinutes(fake()->numberBetween(0, 1440));

        return [
            'host' => fake()->randomElement(['pve-01', 'pve-02', 'web-01', 'db-01']),
            'facility' => fake()->randomElement(['auth', 'daemon', 'kern', 'cron', 'user']),
            'severity' => fake()->randomElement(['emerg', 'alert', 'crit', 'err', 'warning', 'notice', 'info', 'debug']),
            'message' => fake()->sentence(),
            'raw' => fake()->sentence(),
            'logged_at' => $loggedAt,
            'received_at' => $loggedAt,
        ];
    }
}
