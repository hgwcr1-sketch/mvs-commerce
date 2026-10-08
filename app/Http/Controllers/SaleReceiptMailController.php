<?php

namespace App\Http\Controllers;

use App\Mail\SaleReceiptMail;
use App\Models\Company;
use App\Models\Sale;
use App\Services\Sales\SaleReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SaleReceiptMailController extends Controller
{
    public function __invoke(Request $request, Sale $sale, SaleReceiptService $receipts): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:150'],
        ]);
        $companyId = (int) session('active_company_id');
        $company = Company::query()->findOrFail($companyId);
        $sale = $receipts->authorizedSale($sale, $request->user(), $companyId, (int) session('active_branch_id'));

        try {
            $pdf = $receipts->pdf($sale, $company);
            Mail::to($data['email'])->send(new SaleReceiptMail($sale, $pdf->output()));
        } catch (Throwable $exception) {
            report($exception);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No fue posible enviar el comprobante. La venta permanece registrada; puede intentarlo nuevamente.',
                ], 422);
            }

            return back()->withErrors(['email' => 'No fue posible enviar el comprobante. La venta permanece registrada; puede intentarlo nuevamente.']);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Comprobante enviado a {$data['email']}.",
            ]);
        }

        return back()->with('success', "Comprobante enviado a {$data['email']}.");
    }
}
