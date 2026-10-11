<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\AcademiaCatalog;
use App\Services\Cobros\CobrosModalityCatalog;
use App\Services\Cobros\CobrosModalityService;
use App\Services\CompanyLicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BLOQUE 21A-2 — Academia MVS CobrOS.
 *
 * Cubre: acceso autorizado (dueño y cobrador), denegación sin permiso,
 * exactamente tres rutas de capacitación (las claves internas de modalidad no
 * son URL), lecciones sin video ("Próximamente", sin reproductor), aislamiento
 * por empresa activa y resaltado de la modalidad del usuario.
 */
class CobrosAcademiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([], 404)]);
    }

    private function company(string $suffix): Company
    {
        $company = Company::create([
            'trade_name' => 'Academia '.$suffix.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros']);

        return $company;
    }

    /** @return array{0: Company, 1: User} */
    private function context(array $permissions): array
    {
        $company = $this->company('ctx');
        $user = $this->member($company, $permissions);

        return [$company, $user];
    }

    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Rol '.uniqid(),
            'is_active' => true,
        ]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    /** Contenido del <article> de una ruta de capacitación (vacío si no está). */
    private function trackCard(string $content, string $key): string
    {
        preg_match('/id="track-'.preg_quote($key, '/').'".*?<\/article>/s', $content, $matches);

        return $matches[0] ?? '';
    }

    public function test_prestamista_ve_exactamente_las_tres_rutas_y_el_acceso_en_el_menu(): void
    {
        [$company, $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);

        $response = $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.academia.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertSame(3, preg_match_all('/id="track-/', $content));
        $response->assertSee('Prestamista administrador')
            ->assertSee('Cobrador independiente')
            ->assertSee('Cobrador vinculado a prestamista')
            ->assertSee('href="'.route('cobros.academia.index').'"', false);

        // Las claves internas de modalidad no se exponen como rutas visibles.
        $this->assertSame(
            [AcademiaCatalog::TRACK_PRESTAMISTA, AcademiaCatalog::TRACK_INDEPENDIENTE, AcademiaCatalog::TRACK_VINCULADO],
            AcademiaCatalog::keys()
        );
        $this->assertCount(3, AcademiaCatalog::tracks());
    }

    public function test_sin_permiso_acceder_la_academia_es_denegada(): void
    {
        [$company, $user] = $this->context([]);

        $this->actingAs($user)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.academia.index'))
            ->assertForbidden();

        $this->actingAs($user)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.academia.show', ['track' => AcademiaCatalog::TRACK_PRESTAMISTA]))
            ->assertForbidden();
    }

    public function test_claves_internas_de_modalidad_no_son_rutas_de_academia(): void
    {
        [$company, $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);

        foreach (['prestamista', 'cobrador_vinculado', 'cobrador_independiente', 'prestamista-cobrador', 'cobrador-multiprestamista', 'inexistente'] as $slug) {
            $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
                ->get(route('cobros.academia.show', ['track' => $slug]))
                ->assertNotFound();
        }

        // El catálogo de negocio sigue intacto: la Academia no lo modifica.
        $this->assertSame([
            'prestamista',
            'cobrador_vinculado',
            'prestamista_cobrador',
            'cobrador_independiente',
            'cobrador_multiprestamista',
        ], CobrosModalityCatalog::keys());
    }

    public function test_lesson_sin_video_muestra_proximamente_y_no_renderiza_reproductor(): void
    {
        [$company, $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);

        $response = $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.academia.show', ['track' => AcademiaCatalog::TRACK_INDEPENDIENTE]))
            ->assertOk();

        $response->assertSee('Acceso y selección de ruta')
            ->assertSee('Próximamente')
            ->assertDontSee('<video');

        foreach (AcademiaCatalog::tracks() as $track) {
            $this->assertFalse(AcademiaCatalog::hasVideo($track), $track['key']);
            foreach ($track['lessons'] as $lesson) {
                $this->assertArrayHasKey('title', $lesson);
                $this->assertArrayHasKey('description', $lesson);
                $this->assertArrayHasKey('duration', $lesson);
                $this->assertArrayHasKey('video_url', $lesson);
                $this->assertNull($lesson['video_url'], 'No se inventan URLs de video.');
            }
        }
    }

    public function test_cobrador_vinculado_accede_a_las_tres_rutas_desde_su_menu(): void
    {
        [$company, $collector] = $this->context(['cobros.acceder', 'cobros.cobrar']);

        $response = $this->actingAs($collector)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.academia.index'))
            ->assertOk();

        $this->assertSame(3, preg_match_all('/id="track-/', $response->getContent()));
        $response->assertSee('href="'.route('cobros.academia.index').'"', false);
    }

    public function test_modalidad_de_la_membresia_resalta_su_ruta_de_capacitacion(): void
    {
        [$company, $owner] = $this->context(['cobros.acceder', 'cobros.portal_dueno']);
        app(CobrosModalityService::class)->assign($company, $owner, CobrosModalityCatalog::PRESTAMISTA);

        $content = $this->actingAs($owner)->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.academia.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Tu modalidad', $this->trackCard($content, AcademiaCatalog::TRACK_PRESTAMISTA));
        $this->assertStringNotContainsString('Tu modalidad', $this->trackCard($content, AcademiaCatalog::TRACK_INDEPENDIENTE));
        $this->assertStringNotContainsString('Tu modalidad', $this->trackCard($content, AcademiaCatalog::TRACK_VINCULADO));

        // Cobrador vinculado: la modalidad de la membresía marca OTRA ruta.
        [$companyB, $collector] = $this->context(['cobros.acceder', 'cobros.cobrar']);
        app(CobrosModalityService::class)->assign($companyB, $collector, CobrosModalityCatalog::COBRADOR_VINCULADO);

        $contentB = $this->actingAs($collector)->withSession(['active_company_id' => $companyB->id])
            ->get(route('cobros.academia.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Tu modalidad', $this->trackCard($contentB, AcademiaCatalog::TRACK_VINCULADO));
        $this->assertStringNotContainsString('Tu modalidad', $this->trackCard($contentB, AcademiaCatalog::TRACK_PRESTAMISTA));
    }

    public function test_aislamiento_nunca_muestra_la_empresa_no_activa_del_sesion(): void
    {
        $foreign = $this->company('ajena');
        $mine = $this->company('propia');
        $user = $this->member($mine, ['cobros.acceder', 'cobros.portal_dueno']);

        $response = $this->actingAs($user)->withSession(['active_company_id' => $foreign->id])
            ->get(route('cobros.academia.index'))
            ->assertOk();

        $response->assertSee($mine->trade_name)
            ->assertDontSee($foreign->trade_name);
        $this->assertSame($mine->id, (int) session('active_company_id'));
    }
}
