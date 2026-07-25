<?php

namespace App\Modules\Documents\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSrsDocumentRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:100000'],
            'file' => ['nullable', 'file', 'max:2048'],
            'project_id' => ['nullable', 'uuid'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasNotes = filled($this->input('notes'));
            $hasFile = $this->hasFile('file');

            if (! $hasNotes && ! $hasFile) {
                $validator->errors()->add('notes', 'Provide notes text or upload a .txt/.md file.');
            }

            if ($hasFile) {
                $ext = strtolower($this->file('file')?->getClientOriginalExtension() ?? '');
                if (! in_array($ext, ['txt', 'md', 'markdown'], true)) {
                    $validator->errors()->add('file', 'Only .txt and .md files are allowed.');
                }
            }
        });
    }
}
