<?php

namespace App\Modules\Authorization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'permission_codes' => ['array'],
            'permission_codes.*' => ['string', 'exists:permissions,code'],
        ];
    }
}
