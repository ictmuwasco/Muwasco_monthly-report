<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for creating a parameter category.
 *
 * NOTE: live `parameter_categories` table has NO timestamp columns, so writes
 * must use `timestamps = false`.
 */
class StoreParameterCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\ParameterCategory::class);
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:255', 'unique:parameter_categories,name'],
            'description'    => ['nullable', 'string'],
            'display_order'  => ['nullable', 'integer'],
        ];
    }

        /**
     * Ensure default values match the live DB defaults so inserts never fail.
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        if (is_array($data)) {
            $data['display_order'] = $data['display_order'] ?? 0;
        }

        return $data;
    }
}