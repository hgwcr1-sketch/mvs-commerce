<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineHealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'server_time' => now()->toIso8601String(),
            'user_id' => (int) $request->user()->id,
            'company_id' => (int) session('active_company_id'),
            'branch_id' => (int) session('active_branch_id'),
        ]);
    }
}
