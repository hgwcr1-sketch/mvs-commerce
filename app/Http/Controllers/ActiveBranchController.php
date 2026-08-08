<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;

class ActiveBranchController extends Controller
{
    public function update(Request $request)
    {
        $request->validate([
            'branch_id' => [
                'required',
                'integer',
                'exists:branches,id',
            ],
        ]);

        $companyId = session('active_company_id');

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