@extends('layouts.platform')
@section('title', 'Alta comercial')
@section('content')
<div class="mx-auto max-w-4xl space-y-5" data-commercial-onboarding>
    <header><a href="{{ route('platform.index') }}" class="text-sm font-semibold text-amber-700">← Panel Maestro</a><h1 class="mt-2 text-2xl font-bold sm:text-3xl">Alta comercial de tenant</h1><p class="mt-2 text-sm text-slate-600">MVS define acceso y contrato. El propietario completará después sus datos legales, primera sucursal y operación.</p></header>
    <form method="POST" action="{{ route('platform.companies.store') }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">@csrf
        @if($errors->any())<div class="rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ $errors->first() }}</div>@endif
        <section><h2 class="font-bold">Tenant y propietario</h2><div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
            <label class="text-sm font-semibold">Nombre de referencia<input name="trade_name" value="{{ old('trade_name') }}" required class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Nombre del propietario<input name="owner[name]" value="{{ old('owner.name') }}" required class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Correo del propietario<input type="email" name="owner[email]" value="{{ old('owner.email') }}" required class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Teléfono opcional<input name="owner[phone]" value="{{ old('owner.phone') }}" class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
        </div></section>
        <section><h2 class="font-bold">Contrato inicial</h2><div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
            <label class="text-sm font-semibold">Plantilla comercial<select name="license_plan_id" data-pricing-plan class="mt-2 min-h-11 w-full rounded-xl border px-4"><option value="">Contrato personalizado</option>@foreach($licensePlans as $plan)<option value="{{ $plan->id }}" @selected(old('license_plan_id') == $plan->id)>{{ $plan->name }} · {{ $plan->branch_limit ?? '∞' }} suc. / {{ $plan->user_limit ?? '∞' }} usuarios{{ $plan->base_price_usd !== null ? ' · $'.number_format((float) $plan->base_price_usd, 2).'/mes' : '' }}</option>@endforeach</select></label>
            <label class="text-sm font-semibold">Sucursales contratadas<input type="number" min="0" max="500" name="branches" value="{{ old('branches', 1) }}" data-pricing-branches class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Usuarios contratados<input type="number" min="0" max="5000" name="users" value="{{ old('users', 1) }}" data-pricing-users class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Referencia contractual<input name="plan" value="{{ old('plan') }}" class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Estado<select name="status" class="mt-2 min-h-11 w-full rounded-xl border px-4">@foreach(\App\Models\CompanyLicense::STATUSES as $status)<option value="{{ $status }}" @selected(old('status', 'trial') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
            <div class="flex items-end"><label class="flex min-h-11 w-full items-center gap-2 rounded-xl border p-3 text-sm font-semibold"><input type="checkbox" name="commerce_override" value="1" data-pricing-override @checked(old('commerce_override')) class="h-5 w-5">Ajustar precio manualmente</label></div>
            <label class="text-sm font-semibold md:col-span-3">Precio Commerce manual (USD/mes)<input type="number" min="0" step="0.01" name="commerce_price_usd" value="{{ old('commerce_price_usd') }}" data-pricing-manual class="mt-2 min-h-11 w-full rounded-xl border px-4" @disabled(!old('commerce_override'))></label>
        </div>
        <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4" data-pricing-commerce aria-live="polite">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Contrato Commerce (USD)</p>
            <dl class="mt-2 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div><dt class="text-slate-500">Precio base</dt><dd class="font-bold" data-pricing-base>—</dd></div>
                <div><dt class="text-slate-500">Incluye</dt><dd class="font-bold" data-pricing-included>—</dd></div>
                <div><dt class="text-slate-500">Extras</dt><dd class="font-bold" data-pricing-extras>—</dd></div>
                <div><dt class="text-slate-500">Total USD/mes</dt><dd class="font-bold text-primary" data-pricing-total>—</dd></div>
            </dl>
            <p class="mt-2 text-xs text-slate-500" data-pricing-note>Recalculado automáticamente al cambiar plantilla, sucursales o usuarios.</p>
        </div>
        <label class="mt-4 block text-sm font-semibold">Nota comercial<textarea name="notes" class="mt-2 min-h-24 w-full rounded-xl border p-3">{{ old('notes') }}</textarea></label></section>
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><h2 class="font-bold">Facturación electrónica (CRC)</h2><div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
            <label class="text-sm font-semibold">Plan FE<select name="fiscal_plan" data-pricing-fiscal-plan class="mt-2 min-h-11 w-full rounded-xl border px-4">@foreach($fiscalPlans as $code => $fiscal)<option value="{{ $code }}" @selected(old('fiscal_plan', 'none') === $code)>{{ $fiscal['option_label'] }}</option>@endforeach</select></label>
            <label class="text-sm font-semibold">Precio FE manual (CRC/mes)<input type="number" min="0" step="0.01" name="fiscal_price_crc" value="{{ old('fiscal_price_crc') }}" class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
            <label class="text-sm font-semibold">Cuota mensual FE<input type="number" min="0" name="fiscal_quota" value="{{ old('fiscal_quota') }}" class="mt-2 min-h-11 w-full rounded-xl border px-4"></label>
        </div>
        <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4" data-pricing-fiscal aria-live="polite">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Plan FE (CRC)</p>
            <dl class="mt-2 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div><dt class="text-slate-500">Plan</dt><dd class="font-bold" data-pricing-fiscal-name>Ninguno</dd></div>
                <div><dt class="text-slate-500">Neto</dt><dd class="font-bold" data-pricing-fiscal-net>—</dd></div>
                <div><dt class="text-slate-500">IVA 13%</dt><dd class="font-bold" data-pricing-fiscal-iva>—</dd></div>
                <div><dt class="text-slate-500">Total CRC/mes</dt><dd class="font-bold text-primary" data-pricing-fiscal-total>—</dd></div>
            </dl>
            <p class="mt-2 text-xs text-slate-500">El precio FE se mantiene en colones; nunca se suma al contrato Commerce en USD.</p>
        </div></section>
        <fieldset><legend class="font-bold">Módulos habilitados</legend><div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($moduleCatalog as $key => $definition)<label class="flex min-h-11 items-center gap-3 rounded-xl border p-3"><input type="checkbox" name="modules[]" value="{{ $key }}" @checked(in_array($key, old('modules', []), true)) class="h-5 w-5"><span class="font-semibold">{{ $definition['label'] }}</span></label>@endforeach</div></fieldset>
        <button class="min-h-11 w-full rounded-xl bg-amber-500 px-5 font-bold text-slate-950 sm:w-auto">Crear tenant y contrato</button>
    </form>
</div>
<script>
(function () {
    var root = document.querySelector('[data-commercial-onboarding]');
    if (!root) { return; }

    var plans = @json($planCatalog);
    var fiscalPlans = @json($fiscalPlans);
    var IVA = 0.13;

    var planSelect = root.querySelector('[data-pricing-plan]');
    var branchesInput = root.querySelector('[data-pricing-branches]');
    var usersInput = root.querySelector('[data-pricing-users]');
    var overrideBox = root.querySelector('[data-pricing-override]');
    var manualInput = root.querySelector('[data-pricing-manual]');
    var fiscalSelect = root.querySelector('[data-pricing-fiscal-plan]');
    var fiscalPriceInput = root.querySelector('[name="fiscal_price_crc"]');
    var fiscalQuotaInput = root.querySelector('[name="fiscal_quota"]');

    var money = function (value) {
        return '$' + value.toFixed(2);
    };
    var colones = function (value) {
        return '₡' + value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    var set = function (selector, value) {
        var node = root.querySelector(selector);
        if (node) { node.textContent = value; }
    };
    var currentPlan = function () {
        return plans.filter(function (plan) { return String(plan.id) === String(planSelect.value); })[0] || null;
    };

    function renderCommerce() {
        var plan = currentPlan();
        var branches = Math.max(0, parseInt(branchesInput.value, 10) || 0);
        var users = Math.max(0, parseInt(usersInput.value, 10) || 0);

        if (!plan || plan.base === null) {
            set('[data-pricing-base]', plan ? 'A definir' : 'A definir');
            set('[data-pricing-included]', plan ? plan.includedBranches + ' suc. / ' + plan.includedUsers + ' usuarios' : 'Sin plantilla');
            set('[data-pricing-extras]', '—');
            set('[data-pricing-total]', '—');
            set('[data-pricing-note]', overrideBox.checked
                ? 'Indique el precio manual: la plantilla seleccionada no tiene precio base.'
                : 'Seleccione una plantilla con precio o active el ajuste manual.');
            return;
        }

        var extraBranches = Math.max(0, branches - plan.includedBranches);
        var extraUsers = Math.max(0, users - plan.includedUsers);
        var extras = (extraBranches * plan.extraBranch) + (extraUsers * plan.extraUser);
        var calculated = plan.base + extras;

        set('[data-pricing-base]', money(plan.base));
        set('[data-pricing-included]', plan.includedBranches + ' suc. / ' + plan.includedUsers + ' usuarios');
        set('[data-pricing-extras]', extraBranches + ' suc. + ' + extraUsers + ' usuarios = ' + money(extras));
        set('[data-pricing-total]', money(overrideBox.checked && manualInput.value !== '' ? parseFloat(manualInput.value) || 0 : calculated));
        set('[data-pricing-note]', overrideBox.checked
            ? 'Precio manual aplicado: el recálculo automático no modifica el contrato.'
            : 'Recalculado automáticamente al cambiar plantilla, sucursales o usuarios.');
    }

    function renderFiscal() {
        var code = fiscalSelect.value;
        var plan = fiscalPlans[code] || { label: 'Ninguno', price_crc: null };
        var manual = fiscalPriceInput.value !== '' ? parseFloat(fiscalPriceInput.value) : null;
        var net = manual !== null ? manual : plan.price_crc;

        set('[data-pricing-fiscal-name]', plan.label);
        if (net === null || net === undefined) {
            set('[data-pricing-fiscal-net]', '—');
            set('[data-pricing-fiscal-iva]', '—');
            set('[data-pricing-fiscal-total]', '—');
            return;
        }

        var iva = net * IVA;
        set('[data-pricing-fiscal-net]', colones(net));
        set('[data-pricing-fiscal-iva]', colones(iva));
        set('[data-pricing-fiscal-total]', colones(net + iva));

        if (manual === null && plan.quota !== null && fiscalQuotaInput.value === '') {
            fiscalQuotaInput.value = plan.quota;
        }
    }

    function syncOverride() {
        manualInput.disabled = !overrideBox.checked;
        if (!overrideBox.checked) { manualInput.value = ''; }
        renderCommerce();
    }

    [planSelect, branchesInput, usersInput].forEach(function (node) {
        node.addEventListener('input', renderCommerce);
        node.addEventListener('change', renderCommerce);
    });
    overrideBox.addEventListener('change', syncOverride);
    manualInput.addEventListener('input', renderCommerce);
    [fiscalSelect, fiscalPriceInput, fiscalQuotaInput].forEach(function (node) {
        node.addEventListener('input', renderFiscal);
        node.addEventListener('change', renderFiscal);
    });

    syncOverride();
    renderFiscal();
})();
</script>
@endsection
