<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosMigrationNotice;
use App\Models\Company;

/** 13-1A — bandeja del prestamista: «Migración pendiente de revisar» (solo lectura + marcar revisado). */
class MigrationNoticeController extends Controller
{
    public function index()
    {
        $company = $this->activeCompany();
        $notices = CobrosMigrationNotice::query()
            ->where('company_id', $company->id)
            ->with(['route', 'collector'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('cobros.owner.migration-notices', ['company' => $company, 'notices' => $notices]);
    }

    public function review(int $notice)
    {
        $company = $this->activeCompany();
        $row = CobrosMigrationNotice::where('company_id', $company->id)->findOrFail($notice);
        if ($row->status === CobrosMigrationNotice::STATUS_PENDING) {
            $row->update([
                'status' => CobrosMigrationNotice::STATUS_REVIEWED,
                'reviewed_by' => (int) auth()->id(),
                'reviewed_at' => now(),
            ]);
        }

        return redirect()->route('cobros.owner.migrations.notices')->with('status', 'Marcada como revisada.');
    }

    private function activeCompany(): Company
    {
        return Company::query()->findOrFail((int) session('active_company_id'));
    }
}
