<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for updating a parameter.
 */
class UpdateParameterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', \App\Models\Parameter::class);
    }

    public function rules(): array
    {
        $parameterId = $this->route('parameter');
        $parameterId = $parameterId instanceof \App\Models\Parameter ? $parameterId->id : $parameterId;

        return [
            'code'        => ['sometimes', 'string', 'max:10', Rule::unique('parameters', 'code')->ignore($parameterId)],
            'category_id' => ['nullable', 'integer', 'exists:parameter_categories,id'],
            'label'       => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'data_type'   => ['sometimes', Rule::in(['number', 'text', 'currency', 'percentage'])],
            'unit'        => ['nullable', 'string', 'max:50'],
            'required'    => ['sometimes', 'boolean'],
            'display_order' => ['nullable', 'integer'],
        ];
    }

    }