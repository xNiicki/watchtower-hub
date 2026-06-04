<?php

namespace Database\Factories;

use App\Enums\TargetType;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Target>
 */
class TargetFactory extends Factory
{
    /**
     * Default state: an LXC container with a unique name.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => TargetType::Lxc,
            'name' => fake()->unique()->slug(2),
            'external_id' => (string) fake()->numberBetween(100, 999),
            'node' => 'pve',
            'check_config' => null,
            'enabled' => true,
        ];
    }

    public function node(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TargetType::Node,
            'external_id' => null,
            'node' => null,
        ]);
    }

    public function storage(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TargetType::Storage,
            'external_id' => null,
        ]);
    }

    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TargetType::Service,
            'external_id' => null,
            'node' => null,
            'check_config' => ['url' => 'http://192.168.1.1:8080', 'timeout_ms' => 5000],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }
}
