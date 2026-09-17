<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AlertRecipient;
use App\Models\Company;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use App\Services\Notifications\AlertTypeRegistry;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationCenterController extends Controller
{
    public function __construct(
        private NotificationPreferenceService $preferenceService,
    ) {}

    public function index(Request $request): View
    {
        $company = $this->activeCompany();
        $user = $request->user();

        abort_unless($user->hasPermission('notificaciones.ver', $company), 403);

        $filter = $request->input('filter', 'all');
        $alerts = Alert::query()
            ->where('alerts.company_id', $company->id)
            ->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id)->whereNull('dismissed_at'))
            ->with(['recipients' => fn ($q) => $q->where('user_id', $user->id)])
            ->when($filter === 'unread', fn ($q) => $q->whereHas('recipients', fn ($r) => $r->where('user_id', $user->id)->whereNull('read_at')))
            ->when($filter === 'critical', fn ($q) => $q->where('severity', Alert::SEVERITY_CRITICAL))
            ->when($filter === 'attention', fn ($q) => $q->where('severity', Alert::SEVERITY_ATTENTION))
            ->when($filter === 'info', fn ($q) => $q->where('severity', Alert::SEVERITY_INFO))
            ->orderByDesc('occurred_at')
            ->paginate(25)
            ->withQueryString();

        $counts = [
            'all' => Alert::query()
                ->where('alerts.company_id', $company->id)
                ->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id)->whereNull('dismissed_at'))
                ->count(),
            'unread' => Alert::query()
                ->where('alerts.company_id', $company->id)
                ->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id)->whereNull('dismissed_at')->whereNull('read_at'))
                ->count(),
            'critical' => Alert::query()
                ->where('alerts.company_id', $company->id)
                ->where('severity', Alert::SEVERITY_CRITICAL)
                ->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id)->whereNull('dismissed_at'))
                ->count(),
            'attention' => Alert::query()
                ->where('alerts.company_id', $company->id)
                ->where('severity', Alert::SEVERITY_ATTENTION)
                ->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id)->whereNull('dismissed_at'))
                ->count(),
            'info' => Alert::query()
                ->where('alerts.company_id', $company->id)
                ->where('severity', Alert::SEVERITY_INFO)
                ->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id)->whereNull('dismissed_at'))
                ->count(),
        ];

        return view('notifications.index', compact('alerts', 'filter', 'counts'));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $company = $this->activeCompany();
        $user = $request->user();

        abort_unless($user->hasPermission('notificaciones.ver', $company), 403);

        $count = Alert::query()
            ->where('alerts.company_id', $company->id)
            ->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id)->whereNull('read_at')->whereNull('dismissed_at'))
            ->count();

        return response()->json(['count' => $count]);
    }

    public function recent(Request $request): JsonResponse
    {
        $company = $this->activeCompany();
        $user = $request->user();

        abort_unless($user->hasPermission('notificaciones.ver', $company), 403);

        $alerts = Alert::query()
            ->where('alerts.company_id', $company->id)
            ->whereHas('recipients', fn ($q) => $q->where('user_id', $user->id)->whereNull('dismissed_at'))
            ->with(['recipients' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        return response()->json([
            'alerts' => $alerts->map(fn (Alert $alert) => [
                'id' => $alert->id,
                'type' => $alert->type,
                'type_label' => AlertTypeRegistry::label($alert->type),
                'severity' => $alert->severity,
                'status' => $alert->status,
                'title' => $alert->metadata['title'] ?? AlertTypeRegistry::label($alert->type),
                'message' => $alert->metadata['message'] ?? ($alert->notes ?? ''),
                'link' => $alert->link,
                'occurred_at' => $alert->occurred_at?->toIso8601String(),
                'read_at' => $alert->recipients->first()?->read_at?->toIso8601String(),
            ]),
        ]);
    }

    public function markAsRead(Request $request)
    {
        $company = $this->activeCompany();
        $user = $request->user();
        $alertId = $request->input('alert_id');

        abort_unless($user->hasPermission('notificaciones.ver', $company), 403);

        AlertRecipient::query()
            ->whereHas('alert', fn ($q) => $q->where('company_id', $company->id))
            ->where('user_id', $user->id)
            ->when($alertId, fn ($q) => $q->where('alert_id', $alertId))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('notifications.index')->with('success', 'Notificaciones marcadas como leídas.');
    }

    public function dismiss(Request $request, Alert $alert)
    {
        $company = $this->activeCompany();
        $user = $request->user();

        abort_unless($user->hasPermission('notificaciones.ver', $company), 403);
        abort_unless((int) $alert->company_id === (int) $company->id, 404);

        AlertRecipient::query()
            ->where('alert_id', $alert->id)
            ->where('user_id', $user->id)
            ->whereNull('dismissed_at')
            ->update(['dismissed_at' => now()]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('notifications.index')->with('success', 'Notificación descartada.');
    }

    public function preferences(Request $request): View
    {
        $company = $this->activeCompany();
        $user = $request->user();

        abort_unless($user->hasPermission('notificaciones.configurar', $company), 403);

        $this->preferenceService->ensureCompanyDefaults($company);

        $types = AlertTypeRegistry::all();
        $preferences = NotificationPreference::query()
            ->where('company_id', $company->id)
            ->with(['role:id,name', 'user:id,name'])
            ->orderByRaw('CASE
                WHEN user_id IS NOT NULL THEN 1
                WHEN role_id IS NOT NULL THEN 2
                ELSE 3
            END')
            ->get();

        $roles = Role::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $users = $company->users()
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);

        return view('notifications.preferences', compact('types', 'preferences', 'roles', 'users'));
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $company = $this->activeCompany();
        $user = $request->user();

        abort_unless($user->hasPermission('notificaciones.configurar', $company), 403);

        $allowedTypes = implode(',', array_keys(AlertTypeRegistry::all()));

        $data = $request->validate([
            'preferences' => ['nullable', 'array'],
            'preferences.*.type' => ['required', 'string', 'in:'.$allowedTypes],
            'preferences.*.enabled' => ['required', 'boolean'],
            'preferences.*.severity_min' => ['required', 'string', 'in:CRITICA,ATENCION,INFORMATIVA'],
            'preferences.*.scope' => ['required', 'string', 'in:company,role,user'],
            'preferences.*.role_id' => ['nullable', 'integer'],
            'preferences.*.user_id' => ['nullable', 'integer'],
            'override' => ['nullable', 'array'],
            'override.type' => ['nullable', 'string', 'in:'.$allowedTypes],
            'override.scope' => ['nullable', 'string', 'in:role,user'],
            'override.role_id' => ['nullable', 'integer'],
            'override.user_id' => ['nullable', 'integer'],
            'override.enabled' => ['nullable', 'boolean'],
            'override.severity_min' => ['nullable', 'string', 'in:CRITICA,ATENCION,INFORMATIVA'],
        ]);

        foreach ($data['preferences'] ?? [] as $preference) {
            [$role, $prefUser] = $this->resolvePreferenceScope($company, $preference['scope'], $preference['role_id'] ?? null, $preference['user_id'] ?? null);

            $this->preferenceService->setPreference(
                company: $company,
                type: $preference['type'],
                enabled: (bool) $preference['enabled'],
                severityMin: $preference['severity_min'],
                role: $role,
                user: $prefUser,
            );
        }

        $override = $data['override'] ?? [];
        if (($override['type'] ?? '') !== '' && ($override['scope'] ?? '') !== '') {
            [$role, $prefUser] = $this->resolvePreferenceScope($company, $override['scope'], $override['role_id'] ?? null, $override['user_id'] ?? null);
            $this->preferenceService->setPreference(
                company: $company,
                type: $override['type'],
                enabled: (bool) ($override['enabled'] ?? true),
                severityMin: $override['severity_min'] ?? 'INFORMATIVA',
                role: $role,
                user: $prefUser,
            );
        }

        return redirect()->route('notifications.preferences')->with('success', 'Preferencias actualizadas.');
    }

    private function resolvePreferenceScope(Company $company, string $scope, mixed $roleId, mixed $userId): array
    {
        $role = null;
        $prefUser = null;

        if ($scope === 'role') {
            $role = Role::query()->where('company_id', $company->id)->find($roleId);
            abort_unless($role, 422);
        } elseif ($scope === 'user') {
            $prefUser = User::query()
                ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
                ->find($userId);
            abort_unless($prefUser, 422);
        }

        return [$role, $prefUser];
    }

    private function activeCompany(): Company
    {
        $companyId = session('active_company_id');
        $company = Company::find($companyId);
        abort_if(! $company, 404, 'Empresa activa no encontrada.');

        return $company;
    }
}
