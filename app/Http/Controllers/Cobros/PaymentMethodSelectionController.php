<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosBillingContract;
use App\Models\CobrosPaymentConfirmation;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Services\Billing\Adapters\StripePaymentProvider;
use App\Services\Billing\Checkout\CheckoutSessionRequest;
use App\Services\Billing\Checkout\CheckoutSessionService;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;
use App\Services\Cobros\CobrosBillingContractService;
use App\Services\Cobros\CobrosPaymentConfirmationService;
use App\Services\Cobros\CobrosPricingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * BLOQUE 17B-4B/4C — Pantalla de selección de métodos de pago MVS CobrOS.
 *
 * Muestra los métodos de pago disponibles para el contrato actual.
 * 17B-4C: crea sesión Stripe Checkout SOLO en modo TEST, exclusivamente para
 * el pagador del contrato; importe/moneda desde la cotización interna.
 * NO activa licencias: eso ocurre únicamente desde el webhook verificado.
 */
class PaymentMethodSelectionController extends Controller
{
    public function __construct(
        private CobrosBillingContractService $billingContracts,
        private CobrosPricingService $pricing,
        private StripePaymentProvider $stripeProvider,
        private CheckoutSessionService $checkoutSessions,
        private CobrosPaymentConfirmationService $paymentConfirmations,
    ) {}

    /**
     * Muestra la pantalla de métodos de pago para un contrato.
     */
    public function show(Request $request, CobrosBillingContract $contract): View
    {
        $companyId = $this->authorizeContract($request, $contract);

        // Obtener cotización del contrato (fuente de verdad para importe/moneda)
        $quote = $this->pricing->quoteForContract($contract);

        // Verificar disponibilidad de Stripe
        $stripeAvailable = $this->stripeProvider->isAvailable();
        $stripePublishableKey = $stripeAvailable
            ? ($this->stripeProvider->getPublishableKey() ?? '')
            : '';

        // Obtener métodos de pago SINPE y transferencia bancaria de la empresa
        $sinpeMethod = PaymentMethod::query()
            ->forCompany($companyId)
            ->active()
            ->where('type', PaymentMethod::TYPE_SINPE)
            ->first();

        $bankTransferMethod = PaymentMethod::query()
            ->forCompany($companyId)
            ->active()
            ->where('type', PaymentMethod::TYPE_BANK_TRANSFER)
            ->first();

        // Configuración SINPE (desde Panel Maestro futuro, por ahora defaults)
        $sinpeConfig = Config::get('cobros.sinpe', [
            'recipient_name' => 'MARIO VEGA SAENZ',
            'phone_number' => '88133806',
        ]);

        // Referencia de pago: usa transaction_id de confirmación pendiente si existe,
        // o genera una referencia estable basada en contrato + período
        $paymentReference = $this->resolvePaymentReference($contract);

        // 17B-5A: ¿el pagador ya registró su solicitud SINPE pendiente?
        $sinpePending = CobrosPaymentConfirmation::query()
            ->sinpePending($companyId)
            ->where('cobros_billing_contract_id', $contract->id)
            ->exists();

        return view('cobros.payment-methods', [
            'contract' => $contract,
            'company' => Company::findOrFail($companyId),
            'quote' => $quote,
            'stripeAvailable' => $stripeAvailable,
            'stripeTestReady' => $this->stripeTestReady(),
            'stripePublishableKey' => $stripePublishableKey,
            'sinpeMethod' => $sinpeMethod,
            'bankTransferMethod' => $bankTransferMethod,
            'sinpeConfig' => $sinpeConfig,
            'paymentReference' => $paymentReference,
            'sinpePending' => $sinpePending,
        ]);
    }

    /**
     * 17B-5A — El pagador indica que realizó el SINPE Móvil.
     *
     * Registra una solicitud PENDIENTE (importes/moneda/estado definidos por
     * el servicio, nunca por el navegador). NO confirma el pago ni activa
     * licencias: la verificación y aprobación son un proceso posterior.
     */
    public function reportSinpe(Request $request, CobrosBillingContract $contract): RedirectResponse
    {
        $this->authorizeContract($request, $contract);

        $this->paymentConfirmations->requestSinpe($contract, $request->user());

        return redirect()->route('cobros.payment.methods', $contract);
    }

    /**
     * 17B-4C — Crea la sesión Stripe Checkout (solo TEST) y redirige.
     *
     * Importe/moneda salen SIEMPRE de la cotización interna del contrato.
     * Esta acción no activa licencias: la activación ocurre únicamente en el
     * webhook Stripe verificado (17B-3A). El success/cancel_url solo vuelve
     * a esta pantalla informativa.
     */
    public function checkout(Request $request, CobrosBillingContract $contract): RedirectResponse
    {
        $companyId = $this->authorizeContract($request, $contract);

        $successUrl = route('cobros.payment.methods', $contract).'?checkout=exitoso';
        $cancelUrl = route('cobros.payment.methods', $contract).'?checkout=cancelado';

        try {
            $session = $this->checkoutSessions->createForContract(new CheckoutSessionRequest(
                product: 'cobros',
                companyId: (string) $companyId,
                contractReference: (string) $contract->id,
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
                periodYear: now()->year,
                periodMonth: now()->month,
                metadata: ['payer_user_id' => (string) $request->user()->id],
            ));
        } catch (PaymentProviderUnavailableException $e) {
            return redirect()
                ->route('cobros.payment.methods', $contract)
                ->withErrors(['checkout' => $e->getMessage()]);
        }

        // Redirección externa al checkout de Stripe (nunca activa licencias aquí).
        return redirect()->away($session->url);
    }

    /**
     * Autorización compartida: empresa activa + permiso + SOLO el pagador.
     * Devuelve el id de la empresa activa o aborta (404 empresa ajena / 403).
     */
    private function authorizeContract(Request $request, CobrosBillingContract $contract): int
    {
        // Validar que el contrato pertenece a la empresa activa
        $companyId = (int) session('active_company_id');
        abort_unless($contract->company_id === $companyId, 404);

        // Validar que el usuario tiene permiso para acceder a esta pantalla
        abort_unless($request->user()->hasPermission('cobros.acceder', Company::findOrFail($companyId)), 403);

        // 17B-4B: solo QUIEN PAGA accede (payer_user_id persistido del propio
        // contrato, nunca por rol). Oculta la pantalla al cobrador cuya
        // licencia paga el prestamista y a cualquier acceso directo ajeno.
        abort_unless(
            (int) $contract->payer_user_id === (int) $request->user()->id,
            403,
            'Solo el pagador del contrato puede gestionar esta membresía.'
        );

        return $companyId;
    }

    /**
     * Stripe TEST correctamente configurado: modo test + clave secreta test.
     * Sin credenciales (o en modo live) el botón de tarjeta queda deshabilitado.
     */
    private function stripeTestReady(): bool
    {
        return config('billing.stripe.mode') === 'test'
            && ! empty(config('billing.stripe.secret_key_test'))
            && $this->stripeProvider->isAvailable();
    }

    /**
     * Resuelve la referencia de pago para el contrato.
     * Busca confirmación pendiente existente; si no hay, genera referencia estable
     * basada en contrato + período actual.
     */
    private function resolvePaymentReference(CobrosBillingContract $contract): string
    {
        // Buscar confirmación pendiente existente para el período actual
        $existing = \App\Models\CobrosPaymentConfirmation::query()
            ->where('cobros_billing_contract_id', $contract->id)
            ->where('status', \App\Models\CobrosPaymentConfirmation::STATUS_PENDING)
            ->latest('period_year')
            ->latest('period_month')
            ->first();

        if ($existing !== null) {
            return $existing->transaction_id;
        }

        // Generar referencia estable: COB-{contract_id}-{year}{month}
        $now = now()->startOfMonth();
        return sprintf('COB-%d-%04d%02d', $contract->id, $now->year, $now->month);
    }
}