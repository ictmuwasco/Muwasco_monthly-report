<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation for updating a user (admin only — UserPolicy::update).
 * Fields are optional (PATCH semantics); username is immutable to keep
 * historical audit/reporting attribution stable.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'username'  => ['prohibited'],
            'password'  => ['prohibited'], // use dedicated password-reset endpoint
            'email'     => ['sometimes', 'nullable', 'email', 'max:255',
                            Rule::unique('users', 'email')->ignore($target?->id)],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'role'      => ['sometimes', Rule::in(['admin', 'user'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}