<?php

namespace App\Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIntakeSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'raw_content' => ['sometimes', 'nullable', 'string', 'max:200000'],
        ];
    }
}
