<?php

namespace App\Modules\Orchestration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApprovePipelineRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'approver_name' => ['nullable', 'string', 'max:120'],
            'task_ids' => ['nullable', 'array'],
            'task_ids.*' => ['uuid'],
        ];
    }
}
