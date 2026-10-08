<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Licencias individuales CobrOS: en modalidad `individual` cada cobrador
 * (y el dueño si `admin_license_charged`) necesita su propia licencia con
 * estado, inicio, vencimiento, suspensión y renovación. Sin licencia activa
 * no opera CobrOS. En modalidad `company_cupo` no se crean licencias.
 *
 * Backfill conservador: solo para empresas que ya operan en modalidad
 * individual; otorga una licencia activa de 1 año a los cobradores existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros_collector_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('active'); // active | suspended
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('renewed_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id'], 'cobros_license_unique_user');
            $table->index(['company_id', 'status']);
        });

        $this->backfillIndividualLicensees();
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros_collector_licenses');
    }

    /**
     * Otorga licencia inicial (1 año) a los cobradores de empresas que ya
     * tienen contrato individual, para no dejar operativo bloqueado al
     * instalar esta migración.
     */
    private function backfillIndividualLicensees(): void
    {
        $companyIds = DB::table('company_modules')
            ->where('module_key', 'cobros')
            ->where('is_enabled', true)
            ->where('license_mode', 'individual')
            ->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $roleIds = DB::table('roles')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->whereIn('id', function ($query) {
                    $query->select('role_id')->from('permission_role')->whereIn(
                        'permission_id',
                        fn ($sub) => $sub->select('id')->from('permissions')->where('name', 'cobros.cobrar')
                    );
                })
                ->pluck('id');

            if ($roleIds->isEmpty()) {
                continue;
            }

            $userIds = DB::table('company_user')
                ->where('company_id', $companyId)
                ->whereIn('role_id', $roleIds)
                ->pluck('user_id');

            foreach ($userIds as $userId) {
                DB::table('cobros_collector_licenses')->updateOrInsert(
                    ['company_id' => $companyId, 'user_id' => $userId],
                    [
                        'status' => 'active',
                        'starts_at' => now(),
                        'expires_at' => now()->addYear(),
                        'notes' => 'Licencia inicial otorgada al instalar licencias individuales.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
};
