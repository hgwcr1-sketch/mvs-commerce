<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCreditNoteExpirationRequest;
use App\Http\Requests\UpdateLoyaltyMessageTemplatesRequest;
use App\Http\Requests\UpdateLoyaltySettingRequest;
use App\Http\Requests\UpdateWhatsAppSettingRequest;
use App\Models\Company;
use App\Models\LoyaltyMessageTemplate;
use App\Models\LoyaltySetting;
use App\Services\Loyalty\LoyaltyMessageTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SettingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(LoyaltyMessageTemplateService $messageTemplates): View
    {
        $companyId = (int) session('active_company_id');
        $loyaltySetting = LoyaltySetting::query()->where('company_id', $companyId)->first()
            ?? new LoyaltySetting([
                'company_id' => $companyId,
                'is_active' => false,
                'earning_percentage' => '0.0000',
                'birthday_enabled' => false,
                'birthday_points' => '0.0000',
                'returning_customer_enabled' => false,
                'returning_customer_days' => 0,
                'returning_customer_points' => '0.0000',
            ]);

        $company = Company::query()->findOrFail($companyId);

        $loyaltyMessageTemplates = $messageTemplates->templates($companyId);

        return view('settings.index', compact('company', 'loyaltyMessageTemplates', 'loyaltySetting'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateLoyaltySettingRequest $request, string $id): RedirectResponse
    {
        abort_unless($id === 'fidelidad', 404);
        $companyId = (int) session('active_company_id');

        LoyaltySetting::query()->updateOrCreate(
            ['company_id' => $companyId],
            $request->toValues(),
        );

        return redirect()->route('configuracion.index')->with('success', 'Porcentaje de acumulación actualizado correctamente.');
    }

    public function updateWhatsApp(UpdateWhatsAppSettingRequest $request): RedirectResponse
    {
        $company = Company::query()->findOrFail((int) session('active_company_id'));

        $company->update([
            ...$request->safe()->only([
                'default_phone_country_code',
                'whatsapp_phone_country_code',
                'whatsapp_phone',
            ]),
            'whatsapp_enabled' => $request->boolean('whatsapp_enabled'),
        ]);

        return redirect()->route('configuracion.index')->with('success', 'Configuración de WhatsApp actualizada correctamente.');
    }

    public function updateLoyaltyTemplates(UpdateLoyaltyMessageTemplatesRequest $request): RedirectResponse
    {
        $companyId = (int) session('active_company_id');

        foreach ($request->validated('templates') as $type => $body) {
            LoyaltyMessageTemplate::query()->updateOrCreate(
                ['company_id' => $companyId, 'opportunity_type' => $type],
                ['body' => $body],
            );
        }

        return back()->with('success', 'Plantillas de Fidelidad actualizadas correctamente.');
    }

    /**
     * Vigencia configurable de Notas de Crédito por empresa.
     *
     * Los cambios posteriores de la configuración NO modifican las NC
     * existentes; el expires_at se calcula únicamente al emitir.
     */
    public function updateCreditNoteExpiration(UpdateCreditNoteExpirationRequest $request): RedirectResponse
    {
        $company = Company::query()->findOrFail((int) session('active_company_id'));

        $company->update([
            'credit_note_expiration_policy' => $request->validated('credit_note_expiration_policy'),
            'credit_note_custom_expiration_days' => $request->validated('credit_note_expiration_policy') === 'custom'
                ? (int) $request->validated('credit_note_custom_expiration_days')
                : null,
        ]);

        return redirect()->to(route('configuracion.index').'#notas-credito')
            ->with('success', 'Vigencia de notas de crédito actualizada correctamente.');
    }

    public function loyaltySettings(): RedirectResponse
    {
        return redirect()->route('configuracion.index');
    }
}
