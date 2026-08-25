<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Service;
use App\Models\Specialty;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BeautyServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_belongs_to_company_and_preserves_catalog_attributes(): void
    {
        $company = $this->company('Beauty Uno');
        $service = Service::create([
            'company_id' => $company->id,
            'name' => 'Manicura semipermanente',
            'description' => 'Preparación y esmaltado.',
            'duration_minutes' => 75,
            'price' => '18500.1250',
            'estimated_cost' => '3250.5000',
            'preparation_minutes' => 10,
            'buffer_before_minutes' => 5,
            'buffer_after_minutes' => 15,
        ]);

        $this->assertTrue($service->company->is($company));
        $this->assertTrue($company->fresh()->services->contains($service));
        $this->assertSame(75, $service->duration_minutes);
        $this->assertSame('18500.1250', $service->price);
        $this->assertSame('3250.5000', $service->estimated_cost);
        $this->assertSame(10, $service->preparation_minutes);
        $this->assertSame(5, $service->buffer_before_minutes);
        $this->assertSame(15, $service->buffer_after_minutes);
        $this->assertTrue($service->is_active);
    }

    public function test_service_can_have_multiple_specialties_from_its_company(): void
    {
        $company = $this->company('Beauty Uno');
        $service = $this->service($company, 'Diseño integral');
        $nails = Specialty::create(['company_id' => $company->id, 'name' => 'Uñas']);
        $hair = Specialty::create(['company_id' => $company->id, 'name' => 'Cabello']);

        $service->assignSpecialty($nails);
        $service->assignSpecialty($hair);
        $service->assignSpecialty($nails);

        $this->assertCount(2, $service->fresh()->specialties);
        $this->assertTrue($nails->fresh()->services->contains($service));
        $this->assertDatabaseHas('service_specialty', [
            'company_id' => $company->id,
            'service_id' => $service->id,
            'specialty_id' => $nails->id,
        ]);
    }

    public function test_model_rejects_cross_company_specialty_assignment(): void
    {
        $service = $this->service($this->company('Beauty Uno'), 'Manicura');
        $otherCompany = $this->company('Beauty Dos');
        $otherSpecialty = Specialty::create([
            'company_id' => $otherCompany->id,
            'name' => 'Barbería',
        ]);

        try {
            $service->assignSpecialty($otherSpecialty);
            $this->fail('Se permitió asignar una especialidad de otra empresa.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('specialty_id', $exception->errors());
        }

        $this->assertDatabaseCount('service_specialty', 0);
    }

    public function test_database_rejects_cross_company_service_specialty_relation(): void
    {
        $service = $this->service($this->company('Beauty Uno'), 'Manicura');
        $otherCompany = $this->company('Beauty Dos');
        $otherSpecialty = Specialty::create([
            'company_id' => $otherCompany->id,
            'name' => 'Barbería',
        ]);

        $this->expectException(QueryException::class);

        DB::table('service_specialty')->insert([
            'company_id' => $service->company_id,
            'service_id' => $service->id,
            'specialty_id' => $otherSpecialty->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_names_are_unique_per_company_and_company_scope_is_isolated(): void
    {
        $firstCompany = $this->company('Beauty Uno');
        $secondCompany = $this->company('Beauty Dos');
        $this->service($firstCompany, 'Manicura');
        $this->service($secondCompany, 'Manicura');

        $this->assertSame(1, Service::forCompany($firstCompany->id)->count());
        $this->assertSame(
            [$firstCompany->id],
            Service::forCompany($firstCompany->id)->pluck('company_id')->all(),
        );

        $this->expectException(QueryException::class);
        $this->service($firstCompany, 'Manicura');
    }

    public function test_factory_creates_valid_decimal_and_default_values(): void
    {
        $service = Service::factory()->create();

        $this->assertNotNull($service->company);
        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $service->price);
        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $service->estimated_cost);
        $this->assertIsInt($service->duration_minutes);
        $this->assertTrue($service->is_active);
    }

    private function company(string $name): Company
    {
        return Company::create([
            'trade_name' => $name,
            'is_active' => true,
        ]);
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
}
