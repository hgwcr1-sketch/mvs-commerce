<?php

namespace App\Services\Fiscal;

use App\Models\ElectronicDocument;
use App\Models\FiscalSeries;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Autoridad provider-neutral de series fiscales por
 * empresa + ambiente + sucursal + terminal + tipo.
 *
 * Formato oficial de consecutivo (20 dígitos): sucursal(3) + terminal(5)
 * + tipo(2) + secuencia(10). La numeración productiva sigue en el
 * proveedor hasta MvsFiscal; este servicio OBSERVA consecutivos reales,
 * sugiere el siguiente, importa series controladamente y NUNCA resetea
 * (tampoco al cambiar de proveedor: las filas no tienen columna provider).
 */
class FiscalSeriesService
{
    /**
     * @return array{branch: string, terminal: string, type: string, sequence: int}
     *
     * @throws InvalidArgumentException
     */
    public function parseConsecutivo(string $consecutivo): array
    {
        if (preg_match('/^(\d{3})(\d{5})(\d{2})(\d{10})$/', trim($consecutivo), $m) !== 1) {
            throw new InvalidArgumentException('Consecutivo fiscal inválido: se esperan 20 dígitos.');
        }

        $sequence = ltrim($m[4], '0');

        return [
            'branch' => $m[1],
            'terminal' => $m[2],
            'type' => $m[3],
            'sequence' => $sequence === '' ? 0 : (int) $sequence,
        ];
    }

    public function scopeFor(ElectronicDocument $document): ?array
    {
        if ($document->consecutivo === null || trim($document->consecutivo) === '') {
            return null;
        }

        try {
            $parts = $this->parseConsecutivo($document->consecutivo);
        } catch (InvalidArgumentException) {
            return null;
        }

        return [
            'company_id' => $document->company_id,
            'environment' => $document->environment,
            'branch_code' => $parts['branch'],
            'terminal_code' => $parts['terminal'],
            'document_type' => $document->document_type,
        ];
    }

    /**
     * Registra el consecutivo observado si avanza la serie. Idempotente y
     * monótono: jamás retrocede ni resetea.
     */
    public function observe(ElectronicDocument $document): ?FiscalSeries
    {
        $scope = $this->scopeFor($document);

        if ($scope === null) {
            return null;
        }

        $parts = $this->parseConsecutivo($document->consecutivo);

        return DB::transaction(function () use ($scope, $parts, $document) {
            $series = FiscalSeries::query()->where($scope)->lockForUpdate()->first();

            if ($series === null) {
                return FiscalSeries::create($scope + [
                    'last_sequence' => $parts['sequence'],
                    'last_consecutivo' => $document->consecutivo,
                    'last_document_id' => $document->id,
                ]);
            }

            if ($parts['sequence'] > $series->last_sequence) {
                $series->update([
                    'last_sequence' => $parts['sequence'],
                    'last_consecutivo' => $document->consecutivo,
                    'last_document_id' => $document->id,
                ]);
            }

            return $series->fresh();
        });
    }

    public function nextSequence(int $companyId, string $environment, string $branch, string $terminal, string $type): int
    {
        $series = FiscalSeries::query()
            ->where('company_id', $companyId)
            ->where('environment', $environment)
            ->where('branch_code', $branch)
            ->where('terminal_code', $terminal)
            ->where('document_type', $type)
            ->first();

        return ($series?->last_sequence ?? 0) + 1;
    }

    /**
     * Reserva advisory del siguiente número (lock de fila, sin saltos por
     * concurrencia). La numeración productiva la sigue asignando el
     * proveedor; esto prepara la continuidad hacia MvsFiscal.
     */
    public function claimNext(int $companyId, string $environment, string $branch, string $terminal, string $type): int
    {
        return DB::transaction(function () use ($companyId, $environment, $branch, $terminal, $type) {
            $series = FiscalSeries::query()
                ->where('company_id', $companyId)
                ->where('environment', $environment)
                ->where('branch_code', $branch)
                ->where('terminal_code', $terminal)
                ->where('document_type', $type)
                ->lockForUpdate()
                ->first();

            if ($series === null) {
                $series = FiscalSeries::create([
                    'company_id' => $companyId,
                    'environment' => $environment,
                    'branch_code' => $branch,
                    'terminal_code' => $terminal,
                    'document_type' => $type,
                    'last_sequence' => 1,
                ]);

                return 1;
            }

            $series->increment('last_sequence');

            return $series->fresh()->last_sequence;
        });
    }

    /**
     * Migración/importación controlada de una serie conocida. Solo avanza:
     * nunca baja ni resetea lo observado.
     *
     * @throws InvalidArgumentException
     */
    public function import(int $companyId, string $environment, string $branch, string $terminal, string $type, string $lastConsecutivo): FiscalSeries
    {
        $parts = $this->parseConsecutivo($lastConsecutivo);

        if ($parts['branch'] !== $branch || $parts['terminal'] !== $terminal || $parts['type'] !== $type) {
            throw new InvalidArgumentException('El consecutivo no corresponde a la serie indicada.');
        }

        return DB::transaction(function () use ($companyId, $environment, $branch, $terminal, $type, $parts, $lastConsecutivo) {
            $series = FiscalSeries::query()
                ->where('company_id', $companyId)
                ->where('environment', $environment)
                ->where('branch_code', $branch)
                ->where('terminal_code', $terminal)
                ->where('document_type', $type)
                ->lockForUpdate()
                ->first();

            if ($series === null) {
                return FiscalSeries::create([
                    'company_id' => $companyId,
                    'environment' => $environment,
                    'branch_code' => $branch,
                    'terminal_code' => $terminal,
                    'document_type' => $type,
                    'last_sequence' => $parts['sequence'],
                    'last_consecutivo' => $lastConsecutivo,
                ]);
            }

            if ($parts['sequence'] < $series->last_sequence) {
                throw new InvalidArgumentException('La serie importada es anterior a lo observado: no se retrocede.');
            }

            $series->update([
                'last_sequence' => $parts['sequence'],
                'last_consecutivo' => $lastConsecutivo,
                'last_document_id' => null,
            ]);

            return $series->fresh();
        });
    }
}
