<?php

namespace App\Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSrsDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
