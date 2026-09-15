<?php

namespace App\Http\Controllers\MvsPrint;

use App\Http\Controllers\Controller;
use App\Http\Requests\MvsPrint\StoreMvsPrintTerminalRequest;
use App\Http\Requests\MvsPrint\UpdateMvsPrintTerminalRequest;
use App\Models\Branch;
use App\Models\MvsPrint\MvsPrintTerminal;
use App\Services\MvsPrint\EscPosTestTicket;
use App\Services\MvsPrint\QzSigningService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class MvsPrintTerminalsController extends Controller
{
    /**
     * Lista las terminales de impresión de la empresa activa y la sucursal activa.
     */
    public function index(QzSigningService $signing): View
    {
        $companyId = $this->activeCompanyId();
        $branchId = (int) session('active_branch_id');

        $terminals = MvsPrintTerminal::query()
            ->forCompany($companyId)
            ->forBranch($branchId)
            ->orderBy('name')
            ->get();

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('settings.pos.impresion', [
            'terminals' => $terminals,
            'branches' => $branches,
            'branchId' => $branchId,
            // Fase 1: sin certificado x509 provisionado, qz-tray opera en modo
            // diálogos estándar. Cuando se provea el certificado se activa la
            // firma automática sin cambios de código.
            'qzSignedMode' => $signing->isConfigured() && $signing->certificateConfigured(),
        ]);
    }

    /**
     * Crea una terminal POS de impresión para la empresa y sucursal activas.
     */
    public function store(StoreMvsPrintTerminalRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['terminal_uuid'] = Str::uuid();
        $data['company_id'] = $this->activeCompanyId();

        MvsPrintTerminal::create($data);

        return redirect()
            ->route('mvs.print.index')
            ->with('success', 'Terminal de impresión creada correctamente.');
    }

    /**
     * Actualiza la configuración de la terminal (impresora, corte, cajón, etc.).
     */
    public function update(
        UpdateMvsPrintTerminalRequest $request,
        MvsPrintTerminal $terminal,
    ): RedirectResponse {
        $this->ensureOwner($terminal);

        $data = $request->validated();

        if ($data['drawer_command'] === []) {
            $data['drawer_command'] = null;
        }

        $terminal->update($data);

        return redirect()
            ->route('mvs.print.index')
            ->with('success', 'Terminal de impresión actualizada correctamente.');
    }

    /**
     * Cambia el estado activo/desactiva de una terminal (soft toggle).
     */
    public function toggleStatus(MvsPrintTerminal $terminal): RedirectResponse
    {
        $this->ensureOwner($terminal);
        $terminal->update(['enabled' => !$terminal->enabled]);

        return redirect()
            ->route('mvs.print.index')
            ->with('success', $terminal->enabled
                ? 'Terminal activada correctamente.'
                : 'Terminal desactivada correctamente.');
    }

    /**
     * Elimina una terminal de impresión de la empresa activa.
     */
    public function destroy(MvsPrintTerminal $terminal): RedirectResponse
    {
        $this->ensureOwner($terminal);
        $terminal->delete();

        return redirect()
            ->route('mvs.print.index')
            ->with('success', 'Terminal de impresión eliminada correctamente.');
    }

    /**
     * Devuelve el payload ESC/POS de prueba para que el navegador lo envíe
     * a QZ Tray. El backend NO imprime: solo valida y prepara el payload.
     * La firma de la conexión la maneja qz.security mediante /mvs/print/signature.
     */
    public function testPrintPayload(MvsPrintTerminal $terminal): JsonResponse
    {
        $this->ensureOwner($terminal);

        $payload = EscPosTestTicket::build($terminal);

        return response()->json([
            'success' => true,
            'terminal' => $terminal->terminal_uuid,
            'printer' => $terminal->printer_name,
            'paper_width' => $terminal->paper_width,
            'payload' => $payload,
        ]);
    }

    /**
     * Devuelve el payload mínimo para abrir el cajón de forma independiente.
     * Funciona sin importar la configuración de "Abrir cajón después de venta";
     * el botón manual siempre está disponible cuando la terminal tiene cajón habilitado.
     */
    public function openDrawerPayload(MvsPrintTerminal $terminal): JsonResponse
    {
        $this->ensureOwner($terminal);

        $payload = EscPosTestTicket::openDrawerPayload($terminal);

        return response()->json([
            'success' => true,
            'terminal' => $terminal->terminal_uuid,
            'printer' => $terminal->printer_name,
            'payload' => $payload,
        ]);
    }

    /**
     * Firma la petición de QZ Tray para el navegador (flujo oficial).
     * Recibe el mensaje crudo "toSign" que qz-tray entrega al promise de firma,
     * lo firma con la clave privada del servidor y devuelve la firma base64 en
     * texto plano. La clave privada jamás abandona el servidor.
     */
    public function signature(Request $request, QzSigningService $signing): Response
    {
        $data = $request->validate([
            'request' => ['required', 'string', 'max:65536'],
        ]);

        $signature = $signing->sign($data['request']);

        return response($signature, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * Devuelve el certificado X509 público para QZ Tray (modo firmado silencioso).
     *
     * El navegador usa este certificado vía qz.security.setCertificatePromise()
     * para validar las firmas generadas por el servidor.
     * Solo expone el certificado público; la clave privada nunca sale del servidor.
     */
    public function certificate(QzSigningService $signing): Response
    {
        $signing->ensureCertificate();

        return response($signing->certificatePem(), 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * Marca la terminal como visible recientemente (latido del frontend).
     */
    public function heartbeat(MvsPrintTerminal $terminal): JsonResponse
    {
        $this->ensureOwner($terminal);
        $terminal->update(['last_seen_at' => now()]);

        return response()->json(['success' => true, 'last_seen_at' => $terminal->last_seen_at]);
    }

    private function activeCompanyId(): int
    {
        $companyId = session('active_company_id');
        abort_unless($companyId, 404);

        return (int) $companyId;
    }

    private function ensureOwner(MvsPrintTerminal $terminal): void
    {
        abort_unless(
            $terminal->company_id === $this->activeCompanyId()
                && (int) session('active_branch_id') === (int) $terminal->branch_id,
            404,
        );
    }
}