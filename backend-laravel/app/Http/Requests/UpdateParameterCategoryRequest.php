<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for updating a parameter category.
 */
class UpdateParameterCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', \App\Models\ParameterCategory::class);
    }

    public function rules(): array
    {
        $categoryId = $this->route('parameter_category');
        $categoryId = $categoryId instanceof \App\Models\ParameterCategory ? $categoryId->id : $categoryId;

        return [
            'name'          => ['sometimes', 'string', 'max:255', \Illuminate\Validation\Rule::unique('parameter_categories', 'name')->ignore($categoryId)],
            'description'   => ['nullable', 'string'],
            'display_order' => ['nullable', 'integer'],
        ];
    }
}