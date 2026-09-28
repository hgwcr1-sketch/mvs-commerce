<?php

namespace App\Http\Requests;

use App\Services\Loyalty\LoyaltyMessageTemplateService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLoyaltyMessageTemplatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return collect(LoyaltyMessageTemplateService::TYPES)->mapWithKeys(fn ($type) => ["templates.$type" => ['required', 'string', 'max:1000']])->all();
    }
}
