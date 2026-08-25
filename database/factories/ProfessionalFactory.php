<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Professional>
 */
class ProfessionalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::create([
                'trade_name' => fake()->unique()->company(),
                'is_active' => true,
            ])->id,
            'user_id' => function (array $attributes): int {
                $user = User::factory()->create(['is_active' => true]);
                $user->companies()->attach($attributes['company_id']);

                return $user->id;
            },
            'is_active' => true,
        ];
    }
}
