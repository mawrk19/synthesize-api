<?php

namespace App\Modules\Collaboration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApprovePipelineReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'approver_name' => ['nullable', 'string', 'max:120'],
            'guest_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
