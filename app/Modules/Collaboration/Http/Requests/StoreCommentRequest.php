<?php

namespace App\Modules\Collaboration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'guest_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
