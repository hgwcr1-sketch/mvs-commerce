<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BeautyProfessionalSpecialtyTest extends TestCase
{
    use RefreshDatabase;

    public function test_professional_reuses_user_identity_and_belongs_to_a_company(): void
    {
        [$company, , $user] = $this->tenant('Beauty Uno');

        $professional = Professional::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($professional->is_active);
        $this->assertTrue($professional->user->is($user));
        $this->assertTrue($professional->company->is($company));
        $this->assertTrue($user->fresh()->professionalProfiles->contains($professional));
        $this->assertTrue($company->fresh()->professionals->contains($professional));
    }

    public function test_same_user_can_have_one_profile_in_each_company_but_not_two_in_one_company(): void
    {
        [$firstCompany, , $user] = $this->tenant('Beauty Uno');
        [$secondCompany] = $this->tenant('Beauty Dos');
        $user->companies()->attach($secondCompany->id);

        Professional::create(['company_id' => $firstCompany->id, 'user_id' => $user->id]);
        Professional::create(['company_id' => $secondCompany->id, 'user_id' => $user->id]);

        $this->assertCount(2, $user->fresh()->professionalProfiles);

        $this->expectException(QueryException::class);
        Professional::create(['company_id' => $firstCompany->id, 'user_id' => $user->id]);
    }

    public function test_user_must_belong_to_professional_company(): void
    {
        [$company] = $this->tenant('Beauty Uno');
        $unrelatedUser = User::factory()->create(['is_active' => true]);

        $this->expectException(ValidationException::class);

        Professional::create([
            'company_id' => $company->id,
            'user_id' => $unrelatedUser->id,
        ]);
    }

    public function test_professional_can_have_multiple_branches_and_specialties_in_its_company(): void
    {
        [$company, $firstBranch, $user] = $this->tenant('Beauty Uno');
        $secondBranch = $this->branch($company, 'Sucursal dos');
        $professional = Professional::create(['company_id' => $company->id, 'user_id' => $user->id]);
        $nails = Specialty::create(['company_id' => $company->id, 'name' => 'Uñas']);
        $hair = Specialty::create(['company_id' => $company->id, 'name' => 'Cabello']);

        $professional->assignBranch($firstBranch);
        $professional->assignBranch($secondBranch);
        $professional->assignSpecialty($nails);
        $professional->assignSpecialty($hair);

        $this->assertCount(2, $professional->fresh()->branches);
        $this->assertCount(2, $professional->fresh()->specialties);
        $this->assertTrue($firstBranch->fresh()->professionals->contains($professional));
        $this->assertTrue($nails->fresh()->professionals->contains($professional));
        $this->assertSame(
            [$professional->id],
            $company->professionals()->pluck('id')->all(),
        );
        $this->assertCount(2, $company->specialties);
    }

    public function test_model_methods_reject_cross_company_branch_and_specialty_assignments(): void
    {
        [$firstCompany, , $user] = $this->tenant('Beauty Uno');
        [$secondCompany, $otherBranch] = $this->tenant('Beauty Dos');
        $professional = Professional::create(['company_id' => $firstCompany->id, 'user_id' => $user->id]);
        $otherSpecialty = Specialty::create(['company_id' => $secondCompany->id, 'name' => 'Barbería']);

        try {
            $professional->assignBranch($otherBranch);
            $this->fail('Se permitió asignar una sucursal de otra empresa.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_id', $exception->errors());
        }

        try {
            $professional->assignSpecialty($otherSpecialty);
            $this->fail('Se permitió asignar una especialidad de otra empresa.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('specialty_id', $exception->errors());
        }

        $this->assertDatabaseCount('professional_branch', 0);
        $this->assertDatabaseCount('professional_specialty', 0);
    }

    public function test_database_constraints_reject_cross_company_relations_even_without_model_helpers(): void
    {
        [$firstCompany, , $user] = $this->tenant('Beauty Uno');
        [$secondCompany, $otherBranch] = $this->tenant('Beauty Dos');
        $professional = Professional::create(['company_id' => $firstCompany->id, 'user_id' => $user->id]);
        $otherSpecialty = Specialty::create(['company_id' => $secondCompany->id, 'name' => 'Estética']);

        foreach ([
            ['table' => 'professional_branch', 'related' => ['branch_id' => $otherBranch->id]],
            ['table' => 'professional_specialty', 'related' => ['specialty_id' => $otherSpecialty->id]],
        ] as $relation) {
            try {
                DB::table($relation['table'])->insert([
                    'company_id' => $firstCompany->id,
                    'professional_id' => $professional->id,
                    ...$relation['related'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->fail("Se permitió un cruce multiempresa en {$relation['table']}.");
            } catch (QueryException) {
                $this->assertDatabaseCount($relation['table'], 0);
            }
        }
    }

    public function test_specialty_name_is_unique_per_company_and_scopes_are_isolated(): void
    {
        [$firstCompany, , $firstUser] = $this->tenant('Beauty Uno');
        [$secondCompany, , $secondUser] = $this->tenant('Beauty Dos');
        Specialty::create(['company_id' => $firstCompany->id, 'name' => 'Uñas']);
        Specialty::create(['company_id' => $secondCompany->id, 'name' => 'Uñas']);
        Professional::create(['company_id' => $firstCompany->id, 'user_id' => $firstUser->id]);
        Professional::create(['company_id' => $secondCompany->id, 'user_id' => $secondUser->id]);

        $this->assertSame(1, Specialty::forCompany($firstCompany->id)->count());
        $this->assertSame(1, Professional::forCompany($firstCompany->id)->count());

        $this->expectException(QueryException::class);
        Specialty::create(['company_id' => $firstCompany->id, 'name' => 'Uñas']);
    }

    public function test_factories_create_valid_tenant_scoped_models(): void
    {
        $professional = Professional::factory()->create();
        $specialty = Specialty::factory()->create();

        $this->assertTrue($professional->user->companies->contains($professional->company_id));
        $this->assertNotNull($professional->company);
        $this->assertNotNull($specialty->company);
    }

    /** @return array{Company, Branch, User} */
    private function tenant(string $name): array
    {
        $company = Company::create([
            'trade_name' => $name,
            'is_active' => true,
        ]);
        $branch = $this->branch($company, 'Principal');
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);

        return [$company, $branch, $user];
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
}
