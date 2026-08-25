<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $company = Company::create([
            'trade_name' => fake()->unique()->company(),
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => fake()->unique()->words(2, true),
            'code' => strtoupper(fake()->unique()->bothify('??-###')),
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => fake()->name(),
            'customer_type' => 'individual',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);
        $professional = Professional::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $professional->branches()->syncWithoutDetaching([
            $branch->id => ['company_id' => $company->id],
        ]);
        $service = Service::create([
            'company_id' => $company->id,
            'name' => fake()->unique()->words(3, true),
            'duration_minutes' => fake()->numberBetween(15, 180),
            'price' => fake()->randomElement(['5000.0000', '12500.0000', '25000.0000']),
            'estimated_cost' => fake()->randomElement(['0.0000', '2500.0000', '5000.0000']),
        ]);

        $startsAt = fake()->dateTimeBetween('+1 day', '+30 days');
        $endsAt = (clone $startsAt)->modify('+'.fake()->numberBetween(30, 120).' minutes');

        return [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => fake()->randomElement(Appointment::STATUSES),
            'notes' => fake()->optional()->sentence(),
            'cancellation_reason' => null,
            'cancelled_at' => null,
            'no_show_at' => null,
            'deposit_required' => false,
            'deposit_amount' => null,
            'deposit_status' => null,
        ];
    }

    public function reserved(): static
    {
        return $this->state(fn () => ['status' => Appointment::STATUS_RESERVED]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => Appointment::STATUS_CONFIRMED]);
    }

    public function inService(): static
    {
        return $this->state(fn () => ['status' => Appointment::STATUS_IN_SERVICE]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => Appointment::STATUS_COMPLETED]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => Appointment::STATUS_CANCELLED,
            'cancellation_reason' => fake()->sentence(),
            'cancelled_at' => now(),
        ]);
    }

    public function noShow(): static
    {
        return $this->state(fn () => [
            'status' => Appointment::STATUS_NO_SHOW,
            'no_show_at' => now(),
        ]);
    }

    public function withDeposit(): static
    {
        return $this->state(fn () => [
            'deposit_required' => true,
            'deposit_amount' => fake()->randomElement(['5000.0000', '10000.0000', '25000.0000']),
            'deposit_status' => fake()->randomElement(['pending', 'paid', 'refunded']),
        ]);
    }
}
