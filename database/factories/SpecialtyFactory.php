<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Specialty>
 */
class SpecialtyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::create([
                'trade_name' => fake()->unique()->company(),
                'is_active' => true,
            ])->id,
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }
}
