<?php

namespace App\Modules\Iam\Http\Requests;

use App\Modules\Authorization\Enums\PermissionCode;
use App\Modules\Authorization\Services\UserRoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permissions = app(UserRoleService::class)->getUserPermissionCodes(Auth::id());

        return in_array(PermissionCode::CREATE_USERS->value, $permissions, true);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('users', 'username')],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'role_id' => ['nullable', 'uuid', Rule::exists('roles', 'id')],
        ];
    }
}
