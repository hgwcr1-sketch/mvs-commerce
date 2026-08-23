<?php

namespace App\Http\Controllers;

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

        $values = [
            'earning_percentage' => $request->validated('earning_percentage'),
            'redemption_minimum_enabled' => $request->boolean('redemption_minimum_enabled'),
            'redemption_minimum_amount' => $request->validated('redemption_minimum_amount') ?? '0',
            'earn_on_offers' => $request->boolean('earn_on_offers'),
            'birthday_enabled' => $request->boolean('birthday_enabled'),
            'birthday_points' => $request->validated('birthday_points'),
            'returning_customer_enabled' => $request->boolean('returning_customer_enabled'),
            'returning_customer_days' => $request->validated('returning_customer_days'),
            'returning_customer_points' => $request->validated('returning_customer_points'),
            'expiration_enabled' => $request->boolean('expiration_enabled'),
            'expiration_months' => $request->validated('expiration_months'),
        ];
        if (array_key_exists('is_active', $request->validated())) {
            $values['is_active'] = $request->boolean('is_active');
        }
        if ($request->filled('point_value')) {
            $values['point_value'] = $request->validated('point_value');
        }
        if ($request->filled('maximum_redemption_percent')) {
            $values['maximum_redemption_percent'] = $request->validated('maximum_redemption_percent');
        }

        LoyaltySetting::query()->updateOrCreate(
            ['company_id' => $companyId],
            $values,
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

    public function loyaltySettings(): RedirectResponse
    {
        return redirect()->route('configuracion.index');
    }
}
