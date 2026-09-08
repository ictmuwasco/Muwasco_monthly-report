<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating a parameter (references live `parameters` table).
 *
 * NOTE: the live `parameters` table has NO timestamp columns (`created_at`/
 * `updated_at`). Writes must therefore use `timestamps = false` and must not
 * include timestamp keys in the payload.
 */
class StoreParameterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Parameter::class);
    }

    public function rules(): array
    {
        return [
            'code'        => ['required', 'string', 'max:10', 'unique:parameters,code'],
            'category_id' => ['nullable', 'integer', 'exists:parameter_categories,id'],
            'label'       => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'data_type'   => ['sometimes', Rule::in(['number', 'text', 'currency', 'percentage'])],
            'unit'        => ['nullable', 'string', 'max:50'],
            'required'    => ['sometimes', 'boolean'],
            'display_order' => ['nullable', 'integer'],
        ];
    }

        /**
     * Ensure default values match the live DB defaults so inserts never fail.
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        if (is_array($data)) {
            $data['data_type']     = $data['data_type'] ?? 'text';
            $data['required']      = $data['required'] ?? false;
            $data['display_order'] = $data['display_order'] ?? 0;
        }

        return $data;
    }
}