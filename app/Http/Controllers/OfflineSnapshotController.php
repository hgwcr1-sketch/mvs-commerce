<?php

namespace App\Http\Controllers;

use App\Models\OfflineTerminal;
use App\Models\Branch;
use App\Models\Company;
use App\Services\OfflineAuthorizationService;
use App\Services\OfflineSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OfflineSnapshotController extends Controller
{
    public function __construct(
        private OfflineAuthorizationService $authService,
        private OfflineSnapshotService $snapshotService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'terminal_uuid' => ['required', 'string', 'max:36'],
            'authorization' => ['required', 'string'],
        ]);

        $payload = $this->authService->verifyToken($request->authorization);

        if (! $payload) {
            return response()->json([
                'message' => 'Autorización inválida o vencida.',
            ], 401);
        }

        if ((int) $payload['company_id'] !== (int) session('active_company_id')
            || (int) $payload['branch_id'] !== (int) session('active_branch_id')
            || $payload['terminal_uuid'] !== $request->terminal_uuid) {
            return response()->json(['message' => 'Autorización fuera de contexto.'], 403);
        }

        $company = Company::find((int) session('active_company_id'));
        $branch = Branch::find((int) session('active_branch_id'));
        $user = Auth::user();

        if (! $company || ! $branch || (int) $branch->company_id !== (int) $company->id
            || ! $user->companies()->where('companies.id', $company->id)->exists()
            || ! $user->branches()->where('branches.id', $branch->id)->exists()) {
            return response()->json([
                'message' => 'Sesión empresarial no válida.',
            ], 403);
        }

        $terminal = OfflineTerminal::where('terminal_uuid', $request->terminal_uuid)
            ->where('company_id', $company->id)
            ->where('branch_id', $branch->id)
            ->first();

        if (! $terminal) {
            return response()->json([
                'message' => 'Terminal no encontrada.',
            ], 404);
        }

        $snapshot = $this->snapshotService->getSnapshotForAuthorization(
            $company,
            $branch,
            $terminal,
            $user
        );

        return response()->json([
            'snapshot' => $snapshot->snapshot_data,
            'authorization' => $request->authorization,
            'schema_version' => $snapshot->schema_version,
            'generated_at' => $snapshot->generated_at->toIso8601String(),
            'snapshot_size_bytes' => $snapshot->snapshot_size_bytes,
            'product_count' => $snapshot->product_count,
            'customer_count' => $snapshot->customer_count,
        ]);
    }
}
