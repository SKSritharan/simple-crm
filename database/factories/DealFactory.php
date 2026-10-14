<?php

namespace Database\Factories;

use App\DealStage;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Deal> */
class DealFactory extends Factory
{
    /** @return array{title: string, stage: DealStage, amount: string, currency: string} */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'stage' => DealStage::New,
            'amount' => fake()->randomNumber(6).'.00',
            'currency' => 'USD',
        ];
    }

    public function won(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => DealStage::Won,
            'closed_at' => now(),
            'lost_reason' => null,
        ]);
    }

    public function lost(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => DealStage::Lost,
            'closed_at' => now(),
            'lost_reason' => 'Budget unavailable',
        ]);
    }
}
