<?php

namespace App\Modules\Collaboration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'expires_at' => ['nullable', 'date', 'after:now'],
            'allow_comment' => ['sometimes', 'boolean'],
        ];
    }
}
