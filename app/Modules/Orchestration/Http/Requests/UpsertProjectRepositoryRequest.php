<?php

namespace App\Modules\Orchestration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'owner' => ['required', 'string', 'max:100'],
            'repo' => ['required', 'string', 'max:100'],
            'default_branch' => ['nullable', 'string', 'max:100'],
            'base_path' => ['nullable', 'string', 'max:255'],
            'token' => ['nullable', 'string', 'max:500'],
        ];
    }
}
