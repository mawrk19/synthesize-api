<?php

namespace App\Modules\Authorization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddUserToRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string'],
        ];
    }
}
