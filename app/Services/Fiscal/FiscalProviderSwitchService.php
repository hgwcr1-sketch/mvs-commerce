<?php

namespace App\Services\Fiscal;

use App\Contracts\Fiscal\FiscalConnectionVerifiable;
use App\Models\Company;
use App\Models\ElectronicDocument;
use Illuminate\Support\Facades\Log;

/**
 * Gate administrativo para cambio de proveedor fiscal
 * (FacturaEnCR → MvsFiscalProvider futuro).
 *
 * El usuario final conserva el mismo Portal/POS y los históricos
 * conservan su provider original. NO implementa MvsFiscalProvider;
 * solo valida y audita. La ejecución usa la ruta existente
 * (updateConnection + verify), sin caminos duplicados.
 */
class FiscalProviderSwitchService
{
    public const NON_FINAL_STATUSES = ['pending', 'queued', 'signing', 'sent', 'polling'];

    public function __construct(
        private readonly CompanyFiscalConfigService $configs,
    ) {
    }

    /**
     * @return array{ok: bool, blockers: array<int, array{code: string, message: string}>, audit: array<string, mixed>}
     */
    public function canSwitch(Company $company, string $targetProvider, array $credentials = []): array
    {
        $blockers = [];
        $current = $this->configs->ensure($company)->provider;
        $registered = array_keys((array) config('fiscal.providers', []));

        if (! in_array($targetProvider, $registered, true)) {
            $blockers[] = ['code' => 'unknown_target', 'message' => 'El proveedor destino no está registrado.'];
        }

        if ($targetProvider === $current) {
            $blockers[] = ['code' => 'same_provider', 'message' => 'Ya opera con ese proveedor.'];
        }

        $active = ElectronicDocument::query()
            ->where('company_id', $company->id)
            ->whereIn('status', self::NON_FINAL_STATUSES)
            ->count();

        if ($active > 0) {
            $blockers[] = ['code' => 'active_documents', 'message' => "Hay {$active} documento(s) en vuelo: espere su veredicto."];
        }

        if (! $this->seriesReconciled($company)) {
            $blockers[] = ['code' => 'series_unreconciled', 'message' => 'Hay series cuyo último documento no tiene veredicto final.'];
        }

        if ($blockers === [] && $credentials !== []) {
            $verify = $this->verifyTarget($targetProvider, $credentials);

            if (! $verify['connected']) {
                $blockers[] = ['code' => 'target_unverified', 'message' => $verify['message']];
            }
        }

        if ($blockers === [] && $credentials === []) {
            $blockers[] = ['code' => 'target_unverified', 'message' => 'Verifique el proveedor destino antes de cambiar.'];
        }

        $audit = [
            'company_id' => $company->id,
            'from' => $current,
            'to' => $targetProvider,
            'ok' => $blockers === [],
            'at' => now()->toIso8601String(),
        ];

        Log::info('fiscal.provider.switch_check', $audit);

        return ['ok' => $blockers === [], 'blockers' => $blockers, 'audit' => $audit];
    }

    private function seriesReconciled(Company $company): bool
    {
        $unreconciled = \App\Models\FiscalSeries::query()
            ->where('company_id', $company->id)
            ->whereHas('lastDocument', function ($query) {
                $query->whereIn('status', self::NON_FINAL_STATUSES);
            })
            ->count();

        return $unreconciled === 0;
    }

    /**
     * @return array{connected: bool, message: string}
     */
    private function verifyTarget(string $targetProvider, array $credentials): array
    {
        $class = config('fiscal.providers.' . $targetProvider);

        if (! is_string($class) || ! class_exists($class)) {
            return ['connected' => false, 'message' => 'Proveedor destino no configurado.'];
        }

        $provider = app()->make($class);

        if (! $provider instanceof FiscalConnectionVerifiable) {
            return ['connected' => false, 'message' => 'El destino no permite verificar sin emitir.'];
        }

        $result = $provider->verifyConnection($credentials);

        return [
            'connected' => $result->connected,
            'message' => $result->connected ? 'Verificado.' : ($result->message ?? 'No verificado.'),
        ];
    }
}
