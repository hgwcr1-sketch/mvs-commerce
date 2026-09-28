<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

class ActiveBranchController extends Controller
{
    public function update(Request $request)
    {
        if ($request->input('branch_id') === 'all') {
            $company = Company::findOrFail(session('active_company_id'));
            abort_unless($request->user()->companies()->whereKey($company->id)->exists()
                && $request->user()->hasPermission('dashboard.admin', $company), 403);
            return redirect()->route('dashboard', $request->only('period'));
        }

        $request->validate([
            'branch_id' => [
                'required',
                'integer',
                'exists:branches,id',
            ],
        ]);

        $companyId = session('active_company_id');
        abort_unless($request->user()->companies()->whereKey($companyId)->exists(), 403);

        $branch = auth()->user()
            ->branches()
            ->where('branches.id', $request->branch_id)
            ->where('branches.company_id', $companyId)
            ->where('branches.is_active', true)
            ->firstOrFail();

        session([
            'active_branch_id' => $branch->id,
        ]);

        return back();
    }
}
