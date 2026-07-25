<?php

namespace App\Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateSrsFromIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
