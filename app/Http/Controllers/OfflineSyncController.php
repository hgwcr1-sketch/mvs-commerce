<?php

namespace App\Http\Controllers;

use App\Services\OfflineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OfflineSyncController extends Controller
{
    public function __construct(
        private readonly OfflineSyncService $syncService,
    ) {}

    public function sync(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'operation_uuid' => ['required', 'uuid'],
                'operation_type' => ['required', 'string', 'max:50'],
                'terminal_uuid' => ['required', 'uuid'],
                'payload_version' => ['required', 'integer', 'min:1'],
                'created_at_local' => ['required', 'date'],
                'payload' => ['required', 'array', 'min:1'],
                'authorization' => ['required', 'string'],
            ]);

            $user = $request->user();
            $companyId = (int) session('active_company_id');
            $branchId = (int) session('active_branch_id');

            if ($companyId <= 0 || $branchId <= 0) {
                return response()->json([
                    'message' => 'Sesión empresarial no válida.',
                ], 403);
            }

            $result = $this->syncService->sync(
                $request->all(),
                $user,
                $companyId,
                $branchId,
            );
        } catch (ConflictHttpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'La operación contiene datos inválidos.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (\Illuminate\Database\QueryException $exception) {
            return response()->json([
                'message' => 'Error transitorio del servidor al sincronizar la operación.',
            ], 503);
        }

        if ($result['status'] === 'in_progress') {
            return response()->json([
                'success' => true,
                'status' => 'in_progress',
                'operation_uuid' => $result['operation_uuid'],
                'message' => 'La operación ya está siendo procesada. Reintente en breve.',
            ], 202);
        }

        $message = $result['status'] === 'already_processed'
            ? 'Esta venta ya había sido sincronizada.'
            : 'Venta sincronizada correctamente.';

        return response()->json([
            'success' => true,
            'status' => $result['status'],
            'operation_uuid' => $result['operation_uuid'],
            'sale_id' => $result['sale_id'],
            'sale_number' => $result['sale_number'],
            'message' => $message,
        ]);
    }
}