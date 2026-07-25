<?php

namespace App\Modules\Diagrams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDiagramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'mermaid_source' => ['required', 'string', 'max:100000'],
            'title' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
