<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Customers\IdentificationNameLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consulta de NOMBRE por identificación (solo lectura, nunca guarda).
 * Reutiliza la integración común de MVS Commerce; responde `supported=false`
 * cuando la cuenta no tiene proveedor para ese tipo/país (nombre manual).
 */
class CustomerIdentificationLookupController extends Controller
{
    public function __invoke(Request $request, IdentificationNameLookupService $lookup): JsonResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $data = $request->validate([
            'identification' => ['required', 'string', 'max:20'],
            'identification_type' => ['required', 'string', 'in:cedula,juridica,dimex,nit,pasaporte'],
        ]);

        if (! $lookup->supports($company, $data['identification_type'])) {
            return response()->json(['supported' => false, 'found' => false, 'name' => null]);
        }

        $name = $lookup->lookup($company, $data['identification_type'], $data['identification']);

        return response()->json([
            'supported' => true,
            'found' => $name !== null,
            'name' => $name,
        ]);
    }
}
