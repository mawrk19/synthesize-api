<?php

namespace App\Modules\Orchestration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertProjectRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['nullable', Rule::in(['existing', 'new'])],
            'owner' => ['required', 'string', 'max:100'],
            'repo' => ['required', 'string', 'max:100'],
            'default_branch' => ['nullable', 'string', 'max:100'],
            'base_path' => ['nullable', 'string', 'max:255'],
            'token' => ['nullable', 'string', 'max:500'],
            'private' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('mode', 'existing') !== 'new') {
                return;
            }

            if (blank($this->input('token')) && blank(config('services.github.default_token'))) {
                $validator->errors()->add(
                    'token',
                    'A GitHub PAT is required to create a new repository.',
                );
            }
        });
    }
}
