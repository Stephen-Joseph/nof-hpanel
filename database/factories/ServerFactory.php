<?php

namespace Database\Factories;

use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = fake()->unique()->numberBetween(1, 999);

        return [
            'name' => 'Node '.$id.' ('.fake()->city().')',
            'hostname' => 'node'.$id.'.hpanel.internal',
            'private_ip' => '10.200.'.fake()->numberBetween(1, 254).'.'.fake()->numberBetween(1, 254),
            'agent_url' => 'https://10.200.0.'.$id.':8443',
            'status' => ServerStatus::ACTIVE,
            'agent_public_cert_fingerprint' => hash('sha256', fake()->uuid()),
            'max_accounts' => 100,
            'cpu_cores' => 8,
            'memory_mb' => 16384,
            'storage_mb' => 500000,
        ];
    }

    public function maintenance(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::MAINTENANCE,
        ]);
    }

    public function draining(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::DRAINING,
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServerStatus::OFFLINE,
        ]);
    }
}
