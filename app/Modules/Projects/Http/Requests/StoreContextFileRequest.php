<?php

namespace App\Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContextFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->hasFile('file')) {
                return;
            }

            $ext = strtolower($this->file('file')?->getClientOriginalExtension() ?? '');
            $allowed = ['txt', 'md', 'markdown', 'pdf', 'csv', 'json', 'php', 'ts', 'tsx', 'js', 'jsx', 'sql'];

            if (! in_array($ext, $allowed, true)) {
                $validator->errors()->add('file', 'Allowed: '.implode(', ', $allowed));
            }
        });
    }
}
