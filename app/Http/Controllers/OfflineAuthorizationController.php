<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\OfflineTerminal;
use App\Services\OfflineAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OfflineAuthorizationController extends Controller
{
    public function __construct(
        private readonly OfflineAuthorizationService $authorizationService,
    ) {}

    public function authorize(Request $request): JsonResponse
    {
        $request->validate(['terminal_uuid' => 'required|uuid']);
        $user = $request->user();
        $company = $user->isPlatformAdmin()
            ? Company::findOrFail(session('active_company_id'))
            : $this->resolveCompanyFromContext($request);

        try {
            $this->ensurePosAccess($user, $company);
            $branch = Branch::find((int) session('active_branch_id'));
            if (! $branch || (int) $branch->company_id !== (int) $company->id) {
                return response()->json(['message' => 'Sucursal activa no válida.'], 403);
            }

            $terminal = OfflineTerminal::where('terminal_uuid', $request->terminal_uuid)
                ->where('company_id', $company->id)
                ->first();

            if (! $terminal) return response()->json(['message' => 'Terminal no encontrada.'], 404);

            return response()->json($this->authorizationService->authorize($terminal, $user));
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Autorización denegada.',
                'errors' => $e->errors(),
            ], 403);
        }
    }

    public function provision(Request $request): JsonResponse
    {
        $request->validate(['terminal_uuid' => 'required|uuid']);
        $user = $request->user();
        $company = $user->isPlatformAdmin()
            ? Company::findOrFail(session('active_company_id'))
            : $this->resolveCompanyFromContext($request);
        $this->ensurePosAccess($user, $company);
        $branch = Branch::find((int) session('active_branch_id'));
        abort_unless($branch && (int) $branch->company_id === (int) $company->id, 403);
        $terminal = $this->authorizationService->provisionTerminal($company, $branch, $user, $request->terminal_uuid);

        return response()->json(['terminal_uuid' => $terminal->terminal_uuid]);
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string']);
        $payload = $this->authorizationService->verifyToken($request->token);

        if (! $payload) {
            return response()->json([
                'valid' => false,
                'message' => 'Token de autorización inválido o expirado.',
            ], 401);
        }

        return response()->json([
            'valid' => true,
            'terminal_uuid' => $payload['terminal_uuid'],
            'company_id' => $payload['company_id'],
            'branch_id' => $payload['branch_id'],
            'valid_until' => $payload['valid_until'],
        ]);
    }

    private function resolveCompanyFromContext(Request $request): Company
    {
        $companyId = session('active_company_id');
        abort_unless($companyId, 403, 'No hay empresa activa en la sesión.');

        $company = Company::find($companyId);
        abort_unless($company && $company->is_active, 403, 'Empresa activa no válida.');
        abort_unless(
            $request->user()->companies()->where('companies.id', $company->id)->exists(),
            403,
            'El usuario no tiene acceso a esta empresa.'
        );

        return $company;
    }

    public function publicKey(): JsonResponse
    {
        $publicKey = $this->authorizationService->getPublicKey();

        return $publicKey
            ? response()->json(['public_key' => $publicKey])
            : response()->json(['message' => 'Clave pública no disponible.'], 503);
    }

    private function ensurePosAccess($user, Company $company): void
    {
        abort_unless(
            $user->isPlatformAdmin()
                || $user->hasPermission('ventas.crear', $company)
                || $user->hasPermission('configuracion.editar', $company),
            403
        );
    }
}
