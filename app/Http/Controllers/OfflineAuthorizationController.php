<?php

namespace App\Http\Controllers;

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
        $request->validate([
            'terminal_uuid' => 'required|uuid',
        ]);

        $user = $request->user();
        $company = $request->user()->isPlatformAdmin()
            ? \App\Models\Company::findOrFail(session('active_company_id'))
            : $this->resolveCompanyFromContext($request);

        $terminal = OfflineTerminal::where('terminal_uuid', $request->terminal_uuid)
            ->where('company_id', $company->id)
            ->first();

        if (! $terminal) {
            return response()->json([
                'message' => 'Terminal no encontrada para esta empresa.',
            ], 404);
        }

        try {
            $result = $this->authorizationService->authorize($terminal, $user);

            return response()->json($result, 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Autorización denegada.',
                'errors' => $e->errors(),
            ], 403);
        }
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

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

    private function resolveCompanyFromContext(Request $request): \App\Models\Company
    {
        $companyId = session('active_company_id');

        abort_unless($companyId, 403, 'No hay empresa activa en la sesión.');

        $company = \App\Models\Company::find($companyId);
        abort_unless($company, 403, 'Empresa activa no encontrada.');
        abort_unless($company->is_active, 403, 'La empresa no está activa.');

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

        if (! $publicKey) {
            return response()->json([
                'message' => 'Clave pública no disponible. Genere las claves primero.',
            ], 503);
        }

        return response()->json([
            'public_key' => $publicKey,
        ]);
    }
}
