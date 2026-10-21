<?php

namespace Database\Factories;

use App\Enums\AgentJobStatus;
use App\Models\AgentJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentJob>
 */
class AgentJobFactory extends Factory
{
    protected $model = AgentJob::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'account_id' => null,
            'dispatched_by' => User::factory(),
            'action' => 'system.ping',
            'status' => AgentJobStatus::PENDING,
            'payload' => [],
            'result' => null,
            'error_message' => null,
            'dry_run' => false,
            'attempts' => 0,
            'max_attempts' => 3,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => AgentJobStatus::RUNNING,
            'started_at' => now(),
            'attempts' => 1,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => AgentJobStatus::COMPLETED,
            'started_at' => now()->subSeconds(2),
            'completed_at' => now(),
            'attempts' => 1,
            'result' => ['success' => true, 'data' => ['pong' => true]],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => AgentJobStatus::FAILED,
            'started_at' => now()->subSeconds(2),
            'completed_at' => now(),
            'attempts' => 3,
            'error_message' => 'Agent connection timed out after 3 retries',
        ]);
    }
}
