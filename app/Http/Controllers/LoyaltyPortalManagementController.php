<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalAccess;
use App\Models\LoyaltyPortalLink;
use App\Models\LoyaltyPortalPost;
use App\Models\LoyaltyPortalSetting;
use App\Models\Product;
use App\Services\Loyalty\LoyaltyCustomerPortalService;
use App\Services\Loyalty\LoyaltyPortalAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoyaltyPortalManagementController extends Controller
{
    private const PERMISSIONS = ['fidelidad.portal.ver', 'fidelidad.portal.configurar', 'fidelidad.portal.contenido', 'fidelidad.portal.enlaces', 'fidelidad.portal'];

    public function index(Request $request): View
    {
        $company = Company::query()->find((int) session('active_company_id'));
        abort_unless($company && collect(self::PERMISSIONS)->contains(fn ($permission) => $request->user()->hasPermission($permission, $company)), 403);
        $companyId = (int) $company->id;

        $portalUrl = route('loyalty.customer.login', $company);
        $portalQr = null;
        if (app(LoyaltyPortalAccessService::class)->qrSupported()) {
            try {
                $portalQr = app(LoyaltyPortalAccessService::class)->qrSvg($portalUrl);
            } catch (\Throwable $e) {
                $portalQr = null;
            }
        }

        return view('loyalty.portal-management.index', [
            'setting' => LoyaltyPortalSetting::firstOrCreate(['company_id' => $companyId], ['is_active' => true, 'show_active_offers' => true]),
            'posts' => LoyaltyPortalPost::where('company_id', $companyId)->with('product:id,company_id,name,image,sale_price,special_price')->orderBy('sort_order')->latest()->get(),
            'links' => LoyaltyPortalLink::where('company_id', $companyId)->orderBy('sort_order')->get(),
            'products' => Product::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'image', 'sale_price', 'special_price']),
            'customers' => Customer::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'summary' => ['posts' => LoyaltyPortalPost::where('company_id', $companyId)->count(), 'links' => LoyaltyPortalLink::where('company_id', $companyId)->count(), 'accesses' => LoyaltyPortalAccess::where('company_id', $companyId)->whereNull('revoked_at')->count()],
            'portalUrl' => $portalUrl,
            'portalQr' => $portalQr,
        ]);
    }

    public function updateSetting(Request $request): RedirectResponse
    {
        $companyId = (int) session('active_company_id');
        $data = $request->validate(['welcome_message' => ['nullable', 'string', 'max:300'], 'is_active' => ['nullable', 'boolean'], 'show_active_offers' => ['nullable', 'boolean']]);
        $data['is_active'] = $request->boolean('is_active');
        $data['show_active_offers'] = $request->boolean('show_active_offers');
        LoyaltyPortalSetting::updateOrCreate(['company_id' => $companyId], $data);

        return back()->with('success', 'Configuración del portal actualizada.');
    }

    public function preview(Customer $customer, LoyaltyCustomerPortalService $portal): View
    {
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        abort_unless((int) $customer->company_id === (int) $company->id, 404);

        return view('loyalty.portal.show', $portal->data($company, $customer));
    }

    public function storePost(Request $request): RedirectResponse
    {
        $data = $this->postData($request);
        $data['company_id'] = (int) session('active_company_id');
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('loyalty-portal', 'public');
        }
        LoyaltyPortalPost::create($data);

        return back()->with('success', 'Publicación creada.');
    }

    public function updatePost(Request $request, LoyaltyPortalPost $post): RedirectResponse
    {
        $this->companyPost($post);
        $data = $this->postData($request);
        if ($request->hasFile('image')) {
            if ($post->image) {
                Storage::disk('public')->delete($post->image);
            }
            $data['image'] = $request->file('image')->store('loyalty-portal', 'public');
        }
        $post->update($data);

        return back()->with('success', 'Publicación actualizada.');
    }

    public function destroyPost(LoyaltyPortalPost $post): RedirectResponse
    {
        $this->companyPost($post);
        if ($post->image) {
            Storage::disk('public')->delete($post->image);
        }
        $post->delete();

        return back()->with('success', 'Publicación eliminada.');
    }

    public function storeLink(Request $request): RedirectResponse
    {
        LoyaltyPortalLink::create($this->linkData($request) + ['company_id' => (int) session('active_company_id')]);

        return back()->with('success', 'Enlace creado.');
    }

    public function updateLink(Request $request, LoyaltyPortalLink $link): RedirectResponse
    {
        $this->companyLink($link);
        $link->update($this->linkData($request));

        return back()->with('success', 'Enlace actualizado.');
    }

    public function destroyLink(LoyaltyPortalLink $link): RedirectResponse
    {
        $this->companyLink($link);
        $link->delete();

        return back()->with('success', 'Enlace eliminado.');
    }

    private function postData(Request $request): array
    {
        $companyId = (int) session('active_company_id');
        $data = $request->validate(['type' => ['required', Rule::in(LoyaltyPortalPost::TYPES)], 'product_id' => ['nullable', Rule::exists('products', 'id')->where('company_id', $companyId)], 'title' => ['required', 'string', 'max:120'], 'message' => ['nullable', 'string', 'max:500'], 'image' => ['nullable', 'image', 'max:3072'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'], 'is_active' => ['nullable', 'boolean'], 'is_featured' => ['nullable', 'boolean']]);
        $data['is_active'] = $request->boolean('is_active');
        $data['is_featured'] = $request->boolean('is_featured');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function linkData(Request $request): array
    {
        $data = $request->validate(['type' => ['required', Rule::in(LoyaltyPortalLink::TYPES)], 'label' => ['required', 'string', 'max:80'], 'url' => ['required', 'url:http,https', 'max:500'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'], 'is_active' => ['nullable', 'boolean']]);
        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function companyPost(LoyaltyPortalPost $post): void
    {
        abort_unless((int) $post->company_id === (int) session('active_company_id'), 404);
    }

    private function companyLink(LoyaltyPortalLink $link): void
    {
        abort_unless((int) $link->company_id === (int) session('active_company_id'), 404);
    }
}
