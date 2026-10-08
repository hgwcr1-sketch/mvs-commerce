<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 10A-Corrección - el expediente pasa a pertenecer al CLIENTE
 * (company_id + customer_id), permanente e independiente del ciclo de
 * solicitudes. Migración ADITIVA: agrega customer_id (+ índices) y
 * conserva credit_request_id como vínculo histórico. No borra tablas
 * ni registros; SQLite y PostgreSQL compatibles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobros_credit_expedientes', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('company_id');
            // Última edición: auditoría propia cuando no existe solicitud.
            $table->unsignedBigInteger('last_edited_by')->nullable()->after('collector_user_id');
            $table->timestamp('last_edited_at')->nullable()->after('last_edited_by');
            // credit_request_id pasa a opcional (expediente sin solicitud).
            $table->unsignedBigInteger('credit_request_id')->nullable()->change();
        });

        // Backfill portable: solicitud -> customer_id; si no, por identificación.
        $byRequest = DB::table('cobros_credit_requests')->whereNotNull('customer_id')->pluck('customer_id', 'id');
        foreach (DB::table('cobros_credit_expedientes')->orderBy('id')->get() as $expediente) {
            if ($expediente->customer_id !== null) {
                continue;
            }
            $customerId = $byRequest[$expediente->credit_request_id] ?? null;
            if ($customerId === null && $expediente->credit_request_id !== null) {
                $request = DB::table('cobros_credit_requests')->where('id', $expediente->credit_request_id)->first();
                if ($request !== null && $request->identification !== null) {
                    $customerId = DB::table('customers')
                        ->where('company_id', $expediente->company_id)
                        ->where('identification', $request->identification)
                        ->value('id');
                }
            }
            if ($customerId !== null) {
                DB::table('cobros_credit_expedientes')->where('id', $expediente->id)
                    ->update(['customer_id' => $customerId]);
            }
        }

        // Fusionar duplicados (varias solicitudes -> UN expediente por cliente):
        // se conserva el registro más antiguo y se reubican referencias/documentos.
        $duplicates = DB::table('cobros_credit_expedientes')
            ->whereNotNull('customer_id')
            ->select('company_id', 'customer_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->groupBy('company_id', 'customer_id')->having('total', '>', '1')->get();
        foreach ($duplicates as $group) {
            $extraIds = DB::table('cobros_credit_expedientes')
                ->where('company_id', $group->company_id)
                ->where('customer_id', $group->customer_id)
                ->where('id', '!=', $group->keep_id)->pluck('id');
            foreach ($extraIds as $extraId) {
                DB::table('cobros_expediente_references')->where('expediente_id', $extraId)
                    ->update(['expediente_id' => $group->keep_id]);
                DB::table('cobros_expediente_documents')->where('expediente_id', $extraId)
                    ->update(['expediente_id' => $group->keep_id]);
                DB::table('cobros_credit_expedientes')->where('id', $extraId)->delete();
            }
        }

        Schema::table('cobros_credit_expedientes', function (Blueprint $table) {
            $table->unique(['company_id', 'customer_id'], 'ccx_company_customer_unique');
            $table->index(['company_id', 'customer_id'], 'ccx_company_customer_index');
        });
    }

    public function down(): void
    {
        Schema::table('cobros_credit_expedientes', function (Blueprint $table) {
            $table->dropUnique('ccx_company_customer_unique');
            $table->dropIndex('ccx_company_customer_index');
            $table->dropColumn(['customer_id', 'last_edited_by', 'last_edited_at']);
        });
    }
};
