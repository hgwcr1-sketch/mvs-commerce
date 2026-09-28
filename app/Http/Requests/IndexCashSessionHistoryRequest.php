<?php

namespace App\Http\Requests;

use App\Models\CashSession;
use App\Models\CashSessionMailNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCashSessionHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) $this->session()->get('active_company_id');

        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'cash_register_id' => ['nullable', 'integer', Rule::exists('cash_registers', 'id')->where('company_id', $companyId)],
            'cashier_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereExists(fn ($access) => $access
                ->selectRaw('1')->from('company_user')->whereColumn('company_user.user_id', 'users.id')->where('company_user.company_id', $companyId)))],
            'status' => ['nullable', Rule::in([CashSession::STATUS_OPEN, CashSession::STATUS_CLOSING, CashSession::STATUS_CLOSED])],
            'difference' => ['nullable', Rule::in(['with', 'without'])],
            'session_number' => ['nullable', 'string', 'max:40'],
            'mail_status' => ['nullable', Rule::in([
                CashSessionMailNotification::STATUS_PENDING,
                CashSessionMailNotification::STATUS_PROCESSING,
                CashSessionMailNotification::STATUS_SENT,
                CashSessionMailNotification::STATUS_FAILED,
                CashSessionMailNotification::STATUS_SKIPPED,
            ])],
            'mail_type' => ['nullable', Rule::in([CashSessionMailNotification::TYPE_OPENED, CashSessionMailNotification::TYPE_CLOSED])],
        ];
    }
}
