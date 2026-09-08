<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation for creating a user (admin only — UserPolicy::create).
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'username'  => ['required', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'email'     => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'full_name' => ['required', 'string', 'max:255'],
            'password'  => ['required', Password::min(8)],
            'role'      => ['required', Rule::in(['admin', 'user'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Never leave a raw password in request payloads after validation.
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);
        $data['password'] = bcrypt($data['password']);

        return $data;
    }
}