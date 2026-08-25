<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::create([
                'trade_name' => fake()->unique()->company(),
                'is_active' => true,
            ])->id,
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'duration_minutes' => fake()->numberBetween(15, 180),
            'price' => fake()->randomElement(['5000.0000', '12500.0000', '25000.0000']),
            'estimated_cost' => fake()->randomElement(['0.0000', '2500.0000', '5000.0000']),
            'preparation_minutes' => 0,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'is_active' => true,
        ];
    }
}
