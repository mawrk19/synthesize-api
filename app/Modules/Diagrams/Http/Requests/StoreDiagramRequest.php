<?php

namespace App\Modules\Diagrams\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDiagramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:sequence,erd,flowchart,state'],
            'title' => ['required', 'string', 'max:255'],
            'srs_document_id' => ['nullable', 'uuid'],
        ];
    }
}
