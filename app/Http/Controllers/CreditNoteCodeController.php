<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditNote;
use App\Services\Sales\CreditNoteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CreditNoteCodeController extends Controller
{
    /**
     * Límite de regeneraciones por usuario + empresa (5 / 60 s).
     */
    private const ROTATION_ATTEMPTS = 5;

    private const ROTATION_WINDOW = 60;

    /**
     * Lista administrativa de NC Consumer Final con código emitido.
     *
     * Superficie mínima (Fase 4B-4), solo visible con el permiso
     * notas_credito.regenerar_codigo. No se muestran secretos: cada NC
     * muestra únicamente sus datos operativos y, si es elegible, la acción
     * "Regenerar código".
     */
    public function index(Request $request): View
    {
        $companyId = (int) session('active_company_id');

        $query = CreditNote::query()
            ->forCompany($companyId)
            ->whereNull('customer_id')
            ->whereNotNull('application_code_hash')
            ->orderByDesc('id');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where('credit_note_number', 'like', "%{$search}%");
        }

        $notes = $query->paginate(20)->withQueryString();

        return view('notas-credito.codes.index', compact('notes'));
    }

    /**
     * Regenera el código de una NC Consumer Final para la empresa activa.
     *
     * POST, CSRF normal, motivo obligatorio, rate limit 5/min por
     * usuario + empresa. El ID se resuelve SIEMPRE dentro de la empresa
     * activa en el servicio (empresa ajena = 404). El código nuevo se
     * redirige a una página de entrega única respaldada por flash session;
     * jamás viaja en la URL.
     */
    public function regenerate(Request $request, int $creditNote): RedirectResponse
    {
        $companyId = (int) session('active_company_id');

        $reason = trim((string) $request->input('reason', ''));

        if ($reason === '' || mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'reason' => 'Debe indicar un motivo (mínimo 3 caracteres) para regenerar el código.',
            ]);
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'El motivo no puede superar los 500 caracteres.',
            ]);
        }

        $rateKey = 'cn-rotation:'.$companyId.':'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($rateKey, self::ROTATION_ATTEMPTS)) {
            return back()
                ->withErrors([
                    'reason' => 'Demasiados intentos. Inténtelo nuevamente en '.RateLimiter::availableIn($rateKey).' segundos.',
                ])
                ->withInput();
        }

        try {
            $result = app(CreditNoteService::class)->regenerateApplicationCode(
                $companyId,
                $creditNote,
                $request->user(),
                $reason,
            );
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        RateLimiter::hit($rateKey, self::ROTATION_WINDOW);

        return redirect()
            ->route('notas-credito.codes.delivered')
            ->with('credit_note_rotation_delivery', [
                'credit_note_number' => $result['credit_note']->credit_note_number,
                'application_code' => $result['application_code'],
                'generated_at' => now()->toDateTimeString(),
            ]);
    }

    /**
     * Entrega única del nuevo código rotado.
     *
     * El plaintext vive únicamente en el flash session de la respuesta de la
     * regeneración y se consume en este GET: un refresh posterior redirige a
     * la lista sin volver a mostrar el código. La respuesta usa
     * Cache-Control: no-store para que el navegador no cachee la página que
     * muestra el secreto (tampoco vía botón Atrás / bfcache).
     */
    public function delivered(Request $request): Response|RedirectResponse
    {
        $delivery = session('credit_note_rotation_delivery');

        if (! is_array($delivery) || empty($delivery['application_code'])) {
            return redirect()->route('notas-credito.codes.index');
        }

        $company = Company::find((int) session('active_company_id'));
        $branch = Branch::find((int) session('active_branch_id'));

        return response(view('notas-credito.codes.delivered', [
            'delivery' => $delivery,
            'company' => $company,
            'branch' => $branch,
        ]))->header('Cache-Control', 'no-store');
    }
}
