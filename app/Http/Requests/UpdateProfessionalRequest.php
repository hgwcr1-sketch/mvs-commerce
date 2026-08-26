<?php

namespace App\Http\Requests;

use App\Models\Professional;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfessionalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) session('active_company_id');
        $professional = $this->route('professional');

        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('company_user', 'user_id')->where(
                    fn ($query) => $query->where('company_id', $companyId)
                ),
                Rule::unique('professionals', 'user_id')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($professional instanceof Professional ? $professional->id : null),
            ],
            'branches' => ['required', 'array', 'min:1'],
            'branches.*' => [
                'integer',
                'distinct',
                Rule::exists('branches', 'id')->where(
                    fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('is_active', true)
                ),
            ],
            'specialties' => ['nullable', 'array'],
            'specialties.*' => [
                'integer',
                'distinct',
                Rule::exists('specialties', 'id')->where(
                    fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('is_active', true)
                ),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return (new StoreProfessionalRequest)->messages();
    }
}
