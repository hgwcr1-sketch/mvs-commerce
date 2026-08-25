<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BeautyAppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointment_belongs_to_company_and_preserves_all_fields(): void
    {
        $company = $this->company('Beauty Uno');
        $branch = $this->branch($company, 'Principal');
        $customer = $this->customer($company, 'Cliente Uno');
        $professional = $this->professional($company, $branch);
        $service = $this->service($company, 'Corte y peinado');

        $startsAt = now()->addDay()->setTime(10, 0);
        $endsAt = (clone $startsAt)->addMinutes(60);

        $appointment = Appointment::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => Appointment::STATUS_RESERVED,
            'notes' => 'Cliente prefiere productos sin sulfato',
            'deposit_required' => true,
            'deposit_amount' => '5000.0000',
            'deposit_status' => 'pending',
        ]);

        $this->assertTrue($appointment->company->is($company));
        $this->assertTrue($appointment->branch->is($branch));
        $this->assertTrue($appointment->customer->is($customer));
        $this->assertTrue($appointment->professional->is($professional));
        $this->assertTrue($appointment->service->is($service));
        $this->assertSame($startsAt->format('Y-m-d H:i:s'), $appointment->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($endsAt->format('Y-m-d H:i:s'), $appointment->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(Appointment::STATUS_RESERVED, $appointment->status);
        $this->assertSame('Cliente prefiere productos sin sulfato', $appointment->notes);
        $this->assertTrue($appointment->deposit_required);
        $this->assertSame('5000.0000', $appointment->deposit_amount);
        $this->assertSame('pending', $appointment->deposit_status);
        $this->assertNull($appointment->cancellation_reason);
        $this->assertNull($appointment->cancelled_at);
        $this->assertNull($appointment->no_show_at);
        $this->assertTrue($company->fresh()->appointments->contains($appointment));
    }

    public function test_appointment_can_transition_through_all_states(): void
    {
        $company = $this->company('Beauty Uno');
        $branch = $this->branch($company, 'Principal');
        $customer = $this->customer($company, 'Cliente Uno');
        $professional = $this->professional($company, $branch);
        $service = $this->service($company, 'Corte y peinado');

        $appointment = $this->appointment($company, $branch, $customer, $professional, $service);

        $this->assertTrue($appointment->isReserved());
        $this->assertTrue($appointment->isActive());

        $appointment->markAsConfirmed();
        $this->assertTrue($appointment->fresh()->isConfirmed());
        $this->assertTrue($appointment->fresh()->isActive());

        $appointment->markAsInService();
        $this->assertTrue($appointment->fresh()->isInService());
        $this->assertTrue($appointment->fresh()->isActive());

        $appointment->markAsCompleted();
        $this->assertTrue($appointment->fresh()->isCompleted());
        $this->assertFalse($appointment->fresh()->isActive());
    }

    public function test_cancellation_is_auditable_with_reason_and_timestamp(): void
    {
        $company = $this->company('Beauty Uno');
        $branch = $this->branch($company, 'Principal');
        $customer = $this->customer($company, 'Cliente Uno');
        $professional = $this->professional($company, $branch);
        $service = $this->service($company, 'Corte y peinado');

        $appointment = $this->appointment($company, $branch, $customer, $professional, $service);
        $appointment->markAsConfirmed();

        $reason = 'Cliente canceló por enfermedad';
        $appointment->markAsCancelled($reason);

        $appointment = $appointment->fresh();
        $this->assertTrue($appointment->isCancelled());
        $this->assertSame($reason, $appointment->cancellation_reason);
        $this->assertNotNull($appointment->cancelled_at);
        $this->assertNull($appointment->no_show_at);
    }

    public function test_no_show_is_auditable_with_timestamp(): void
    {
        $company = $this->company('Beauty Uno');
        $branch = $this->branch($company, 'Principal');
        $customer = $this->customer($company, 'Cliente Uno');
        $professional = $this->professional($company, $branch);
        $service = $this->service($company, 'Corte y peinado');

        $appointment = $this->appointment($company, $branch, $customer, $professional, $service);
        $appointment->markAsConfirmed();

        $appointment->markAsNoShow();

        $appointment = $appointment->fresh();
        $this->assertTrue($appointment->isNoShow());
        $this->assertNotNull($appointment->no_show_at);
        $this->assertNull($appointment->cancellation_reason);
        $this->assertNull($appointment->cancelled_at);
    }

    public function test_model_rejects_cross_company_branch(): void
    {
        [$firstCompany, $firstBranch] = $this->tenantWithBranch('Beauty Uno');
        [$secondCompany, $secondBranch] = $this->tenantWithBranch('Beauty Dos');
        $customer = $this->customer($firstCompany, 'Cliente Uno');
        $professional = $this->professional($firstCompany, $firstBranch);
        $service = $this->service($firstCompany, 'Corte');

        try {
            Appointment::create([
                'company_id' => $firstCompany->id,
                'branch_id' => $secondBranch->id,
                'customer_id' => $customer->id,
                'professional_id' => $professional->id,
                'service_id' => $service->id,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHour(),
            ]);
            $this->fail('Se permitió crear cita con sucursal de otra empresa.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_id', $exception->errors());
        }
    }

    public function test_model_rejects_cross_company_customer(): void
    {
        [$firstCompany, $firstBranch] = $this->tenantWithBranch('Beauty Uno');
        [$secondCompany] = $this->tenant('Beauty Dos');
        $customer = $this->customer($secondCompany, 'Cliente Otra Empresa');
        $professional = $this->professional($firstCompany, $firstBranch);
        $service = $this->service($firstCompany, 'Corte');

        try {
            Appointment::create([
                'company_id' => $firstCompany->id,
                'branch_id' => $firstBranch->id,
                'customer_id' => $customer->id,
                'professional_id' => $professional->id,
                'service_id' => $service->id,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHour(),
            ]);
            $this->fail('Se permitió crear cita con cliente de otra empresa.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }
    }

    public function test_model_rejects_cross_company_professional(): void
    {
        [$firstCompany, $firstBranch] = $this->tenantWithBranch('Beauty Uno');
        [$secondCompany, $secondBranch] = $this->tenantWithBranch('Beauty Dos');
        $customer = $this->customer($firstCompany, 'Cliente Uno');
        $professional = $this->professional($secondCompany, $secondBranch);
        $service = $this->service($firstCompany, 'Corte');

        try {
            Appointment::create([
                'company_id' => $firstCompany->id,
                'branch_id' => $firstBranch->id,
                'customer_id' => $customer->id,
                'professional_id' => $professional->id,
                'service_id' => $service->id,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHour(),
            ]);
            $this->fail('Se permitió crear cita con profesional de otra empresa.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('professional_id', $exception->errors());
        }
    }

    public function test_model_rejects_cross_company_service(): void
    {
        [$firstCompany, $firstBranch] = $this->tenantWithBranch('Beauty Uno');
        [$secondCompany] = $this->tenant('Beauty Dos');
        $customer = $this->customer($firstCompany, 'Cliente Uno');
        $professional = $this->professional($firstCompany, $firstBranch);
        $service = $this->service($secondCompany, 'Servicio Otra Empresa');

        try {
            Appointment::create([
                'company_id' => $firstCompany->id,
                'branch_id' => $firstBranch->id,
                'customer_id' => $customer->id,
                'professional_id' => $professional->id,
                'service_id' => $service->id,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHour(),
            ]);
            $this->fail('Se permitió crear cita con servicio de otra empresa.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('service_id', $exception->errors());
        }
    }

    public function test_model_rejects_professional_not_assigned_to_branch(): void
    {
        $company = $this->company('Beauty Uno');
        $firstBranch = $this->branch($company, 'Sucursal Uno');
        $secondBranch = $this->branch($company, 'Sucursal Dos');
        $customer = $this->customer($company, 'Cliente Uno');
        $professional = $this->professional($company, $firstBranch);
        $service = $this->service($company, 'Corte');

        try {
            Appointment::create([
                'company_id' => $company->id,
                'branch_id' => $secondBranch->id,
                'customer_id' => $customer->id,
                'professional_id' => $professional->id,
                'service_id' => $service->id,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHour(),
            ]);
            $this->fail('Se permitió crear cita con profesional no asignado a la sucursal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('professional_id', $exception->errors());
        }
    }

    public function test_model_rejects_starts_at_after_ends_at(): void
    {
        $company = $this->company('Beauty Uno');
        $branch = $this->branch($company, 'Principal');
        $customer = $this->customer($company, 'Cliente Uno');
        $professional = $this->professional($company, $branch);
        $service = $this->service($company, 'Corte');

        try {
            Appointment::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'professional_id' => $professional->id,
                'service_id' => $service->id,
                'starts_at' => now()->addDay()->addHour(),
                'ends_at' => now()->addDay(),
            ]);
            $this->fail('Se permitió crear cita con fecha de inicio posterior a fin.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ends_at', $exception->errors());
        }
    }

    public function test_database_constraints_reject_cross_company_relations(): void
    {
        [$firstCompany, $firstBranch] = $this->tenantWithBranch('Beauty Uno');
        [$secondCompany, $secondBranch] = $this->tenantWithBranch('Beauty Dos');
        $customer = $this->customer($firstCompany, 'Cliente Uno');
        $professional = $this->professional($firstCompany, $firstBranch);
        $service = $this->service($firstCompany, 'Corte');

        foreach ([
            ['field' => 'branch_id', 'value' => $secondBranch->id],
            ['field' => 'customer_id', 'value' => $this->customer($secondCompany, 'Cliente Dos')->id],
            ['field' => 'professional_id', 'value' => $this->professional($secondCompany, $secondBranch)->id],
            ['field' => 'service_id', 'value' => $this->service($secondCompany, 'Otro Servicio')->id],
        ] as $case) {
            $data = [
                'company_id' => $firstCompany->id,
                'branch_id' => $firstBranch->id,
                'customer_id' => $customer->id,
                'professional_id' => $professional->id,
                'service_id' => $service->id,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $data[$case['field']] = $case['value'];

            try {
                DB::table('appointments')->insert($data);
                $this->fail("Se permitió un cruce multiempresa en {$case['field']}.");
            } catch (QueryException) {
                $this->assertDatabaseCount('appointments', 0);
            }
        }
    }

    public function test_scopes_filter_by_status_correctly(): void
    {
        $company = $this->company('Beauty Uno');
        $branch = $this->branch($company, 'Principal');
        $customer = $this->customer($company, 'Cliente Uno');
        $professional = $this->professional($company, $branch);
        $service = $this->service($company, 'Corte');

        $reserved = $this->appointment($company, $branch, $customer, $professional, $service);
        $confirmed = $this->appointment($company, $branch, $customer, $professional, $service);
        $confirmed->markAsConfirmed();
        $completed = $this->appointment($company, $branch, $customer, $professional, $service);
        $completed->markAsConfirmed();
        $completed->markAsInService();
        $completed->markAsCompleted();
        $cancelled = $this->appointment($company, $branch, $customer, $professional, $service);
        $cancelled->markAsConfirmed();
        $cancelled->markAsCancelled('Motivo');

        $this->assertCount(1, Appointment::forCompany($company->id)->byStatus(Appointment::STATUS_RESERVED)->get());
        $this->assertCount(1, Appointment::forCompany($company->id)->byStatus(Appointment::STATUS_CONFIRMED)->get());
        $this->assertCount(1, Appointment::forCompany($company->id)->byStatus(Appointment::STATUS_COMPLETED)->get());
        $this->assertCount(1, Appointment::forCompany($company->id)->byStatus(Appointment::STATUS_CANCELLED)->get());
        $this->assertCount(2, Appointment::forCompany($company->id)->active()->get());
        $this->assertCount(1, Appointment::forCompany($company->id)->completed()->get());
        $this->assertCount(1, Appointment::forCompany($company->id)->cancelled()->get());
    }

    public function test_scopes_filter_by_time(): void
    {
        $company = $this->company('Beauty Uno');
        $branch = $this->branch($company, 'Principal');
        $customer = $this->customer($company, 'Cliente Uno');
        $professional = $this->professional($company, $branch);
        $service = $this->service($company, 'Corte');

        $past = $this->appointment($company, $branch, $customer, $professional, $service, now()->subDay());
        $past->markAsCompleted();

        $future = $this->appointment($company, $branch, $customer, $professional, $service, now()->addDay());

        $this->assertCount(1, Appointment::forCompany($company->id)->upcoming()->get());
        $this->assertCount(1, Appointment::forCompany($company->id)->past()->get());
    }

    public function test_company_scope_is_isolated(): void
    {
        [$firstCompany, $firstBranch] = $this->tenantWithBranch('Beauty Uno');
        [$secondCompany, $secondBranch] = $this->tenantWithBranch('Beauty Dos');
        $customer1 = $this->customer($firstCompany, 'Cliente Uno');
        $customer2 = $this->customer($secondCompany, 'Cliente Dos');
        $professional1 = $this->professional($firstCompany, $firstBranch);
        $professional2 = $this->professional($secondCompany, $secondBranch);
        $service1 = $this->service($firstCompany, 'Corte');
        $service2 = $this->service($secondCompany, 'Corte');

        $this->appointment($firstCompany, $firstBranch, $customer1, $professional1, $service1);
        $this->appointment($secondCompany, $secondBranch, $customer2, $professional2, $service2);

        $this->assertCount(1, Appointment::forCompany($firstCompany->id)->get());
        $this->assertCount(1, Appointment::forCompany($secondCompany->id)->get());
        $this->assertSame(
            [$firstCompany->id],
            Appointment::forCompany($firstCompany->id)->pluck('company_id')->all(),
        );
    }

    public function test_factory_creates_valid_appointment(): void
    {
        $company = $this->company('Test Factory');
        $branch = $this->branch($company, 'Principal');
        $customer = $this->customer($company, 'Cliente Factory');
        $professional = $this->professional($company, $branch);
        $service = $this->service($company, 'Servicio Factory');

        $professional->branches()->syncWithoutDetaching([
            $branch->id => ['company_id' => $company->id],
        ]);

        $appointment = Appointment::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
        ]);

        $this->assertNotNull($appointment->company);
        $this->assertNotNull($appointment->branch);
        $this->assertNotNull($appointment->customer);
        $this->assertNotNull($appointment->professional);
        $this->assertNotNull($appointment->service);
        $this->assertTrue($appointment->starts_at > now());
        $this->assertTrue($appointment->ends_at > $appointment->starts_at);
        $this->assertContains($appointment->status, Appointment::STATUSES);
        $this->assertTrue($appointment->professional->branches->contains($appointment->branch));
    }

    public function test_factory_states_work(): void
    {
        $company = $this->company('Test Factory');
        $branch = $this->branch($company, 'Principal');
        $customer = $this->customer($company, 'Cliente Factory');
        $professional = $this->professional($company, $branch);
        $service = $this->service($company, 'Servicio Factory');

        $professional->branches()->syncWithoutDetaching([
            $branch->id => ['company_id' => $company->id],
        ]);

        $reserved = Appointment::factory()->reserved()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
        ]);
        $this->assertTrue($reserved->isReserved());

        $confirmed = Appointment::factory()->confirmed()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
        ]);
        $this->assertTrue($confirmed->isConfirmed());

        $inService = Appointment::factory()->inService()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
        ]);
        $this->assertTrue($inService->isInService());

        $completed = Appointment::factory()->completed()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
        ]);
        $this->assertTrue($completed->isCompleted());

        $cancelled = Appointment::factory()->cancelled()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
        ]);
        $this->assertTrue($cancelled->isCancelled());
        $this->assertNotNull($cancelled->cancellation_reason);
        $this->assertNotNull($cancelled->cancelled_at);

        $noShow = Appointment::factory()->noShow()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
        ]);
        $this->assertTrue($noShow->isNoShow());
        $this->assertNotNull($noShow->no_show_at);
    }

    public function test_factory_with_deposit(): void
    {
        $company = $this->company('Test Factory');
        $branch = $this->branch($company, 'Principal');
        $customer = $this->customer($company, 'Cliente Factory');
        $professional = $this->professional($company, $branch);
        $service = $this->service($company, 'Servicio Factory');

        $professional->branches()->syncWithoutDetaching([
            $branch->id => ['company_id' => $company->id],
        ]);

        $appointment = Appointment::factory()->withDeposit()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
        ]);

        $this->assertTrue($appointment->deposit_required);
        $this->assertNotNull($appointment->deposit_amount);
        $this->assertNotNull($appointment->deposit_status);
        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $appointment->deposit_amount);
    }

    private function company(string $name): Company
    {
        return Company::create([
            'trade_name' => $name,
            'is_active' => true,
        ]);
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => strtoupper(str_replace(' ', '-', $name)).'-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function customer(Company $company, string $name): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'name' => $name,
            'customer_type' => 'individual',
            'is_active' => true,
        ]);
    }

    private function professional(Company $company, Branch $branch): Professional
    {
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

        return $professional;
    }

    private function service(Company $company, string $name): Service
    {
        return Service::create([
            'company_id' => $company->id,
            'name' => $name,
            'duration_minutes' => 60,
            'price' => '15000.0000',
            'estimated_cost' => '2500.0000',
        ]);
    }

    private function appointment(
        Company $company,
        Branch $branch,
        Customer $customer,
        Professional $professional,
        Service $service,
        ?\DateTimeInterface $startsAt = null
    ): Appointment {
        $startsAt = $startsAt ?? now()->addDay()->setTime(10, 0);
        $endsAt = (clone $startsAt)->addMinutes($service->duration_minutes);

        return Appointment::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => Appointment::STATUS_RESERVED,
        ]);
    }

    /** @return array{Company, Branch} */
    private function tenantWithBranch(string $name): array
    {
        $company = $this->company($name);
        $branch = $this->branch($company, 'Principal');

        return [$company, $branch];
    }

    /** @return array{Company, Branch, User} */
    private function tenant(string $name): array
    {
        $company = $this->company($name);
        $branch = $this->branch($company, 'Principal');
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);

        return [$company, $branch, $user];
    }
}
