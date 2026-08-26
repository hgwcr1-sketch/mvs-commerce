<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProfessionalRequest;
use App\Http\Requests\UpdateProfessionalRequest;
use App\Models\Branch;
use App\Models\Professional;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProfessionalController extends Controller
{
    public function index(Request $request): View
    {
        $companyId = $this->activeCompanyId();
        $branchId = $request->integer('branch_id') ?: null;

        if ($branchId !== null) {
            Branch::query()->where('company_id', $companyId)->findOrFail($branchId);
        }

        $professionals = Professional::query()
            ->forCompany($companyId)
            ->with(['user', 'branches', 'specialties'])
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = trim((string) $request->input('search'));
                $query->whereHas('user', fn (Builder $userQuery) => $userQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn (Builder $query) => $query
                ->where('is_active', $request->boolean('status')))
            ->when($branchId, fn (Builder $query) => $query->forBranch($companyId, $branchId))
            ->orderBy(
                User::query()->select('name')->whereColumn('users.id', 'professionals.user_id')
            )
            ->paginate(12)
            ->withQueryString();

        return view('professionals.index', [
            'professionals' => $professionals,
            'branches' => $this->branches($companyId),
        ]);
    }

    public function create(): View
    {
        $companyId = $this->activeCompanyId();

        return view('professionals.create', $this->formData($companyId));
    }

    public function store(StoreProfessionalRequest $request): RedirectResponse
    {
        $companyId = $this->activeCompanyId();
        $data = $request->validated();

        DB::transaction(function () use ($companyId, $data): void {
            $professional = Professional::create([
                'company_id' => $companyId,
                'user_id' => $data['user_id'],
                'is_active' => $data['is_active'],
            ]);

            $this->syncAssignments($professional, $data['branches'], $data['specialties'] ?? []);
        });

        return redirect()->route('professionals.index')
            ->with('success', 'Profesional registrado correctamente.');
    }

    public function show(Professional $professional): View
    {
        $this->ensureBelongsToActiveCompany($professional);
        $professional->load(['user', 'branches', 'specialties']);

        return view('professionals.show', compact('professional'));
    }

    public function edit(Professional $professional): View
    {
        $companyId = $this->activeCompanyId();
        $this->ensureBelongsToActiveCompany($professional);
        $professional->load(['branches', 'specialties']);

        return view('professionals.edit', [
            'professional' => $professional,
            ...$this->formData($companyId, $professional),
        ]);
    }

    public function update(UpdateProfessionalRequest $request, Professional $professional): RedirectResponse
    {
        $this->ensureBelongsToActiveCompany($professional);
        $data = $request->validated();

        DB::transaction(function () use ($professional, $data): void {
            $professional->update([
                'user_id' => $data['user_id'],
                'is_active' => $data['is_active'],
            ]);

            $this->syncAssignments($professional, $data['branches'], $data['specialties'] ?? []);
        });

        return redirect()->route('professionals.index')
            ->with('success', 'Profesional actualizado correctamente.');
    }

    public function destroy(Professional $professional): RedirectResponse
    {
        $this->ensureBelongsToActiveCompany($professional);
        $professional->delete();

        return redirect()->route('professionals.index')
            ->with('success', 'Perfil profesional eliminado correctamente.');
    }

    private function formData(int $companyId, ?Professional $professional = null): array
    {
        $users = User::query()
            ->whereHas('companies', fn (Builder $query) => $query->whereKey($companyId))
            ->where(function (Builder $query) use ($companyId, $professional): void {
                $query->whereDoesntHave('professionalProfiles', fn (Builder $professionalQuery) => $professionalQuery
                    ->where('company_id', $companyId));

                if ($professional !== null) {
                    $query->orWhereKey($professional->user_id);
                }
            })
            ->orderBy('name')
            ->get();

        return [
            'users' => $users,
            'branches' => $this->branches($companyId),
            'specialties' => Specialty::query()
                ->forCompany($companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'selectedBranchIds' => $professional?->branches->modelKeys() ?? [],
            'selectedSpecialtyIds' => $professional?->specialties->modelKeys() ?? [],
        ];
    }

    private function branches(int $companyId)
    {
        return Branch::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    private function syncAssignments(Professional $professional, array $branchIds, array $specialtyIds): void
    {
        $branchAssignments = collect($branchIds)->mapWithKeys(
            fn ($branchId) => [(int) $branchId => ['company_id' => $professional->company_id]]
        );
        $specialtyAssignments = collect($specialtyIds)->mapWithKeys(
            fn ($specialtyId) => [(int) $specialtyId => ['company_id' => $professional->company_id]]
        );

        $professional->branches()->sync($branchAssignments);
        $professional->specialties()->sync($specialtyAssignments);
    }

    private function activeCompanyId(): int
    {
        $companyId = session('active_company_id');
        abort_unless($companyId, 403, 'No hay una empresa activa.');

        return (int) $companyId;
    }

    private function ensureBelongsToActiveCompany(Professional $professional): void
    {
        abort_unless((int) $professional->company_id === $this->activeCompanyId(), 404);
    }
}
