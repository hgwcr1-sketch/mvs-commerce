<?php

namespace App\Http\Controllers;

use App\Services\Cabys\LocalCabysCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Búsqueda del catálogo CABYS local para el formulario de productos.
 *
 * Es la ÚNICA puerta de selección de un código: no hay texto libre ni
 * consulta externa. Sin versión activa responde con el estado `no_catalog`
 * para que la UI lo informe sin romper el formulario.
 */
class CabysController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        $result = app(LocalCabysCatalog::class)->search($query, 30);

        return response()->json($result);
    }
}
