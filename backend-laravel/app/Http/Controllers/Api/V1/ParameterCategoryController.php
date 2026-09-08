<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreParameterCategoryRequest;
use App\Http\Requests\UpdateParameterCategoryRequest;
use App\Models\ParameterCategory;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parameter categories API — read (any authenticated) + admin write.
 *
 * NOTE: live `parameter_categories` has no timestamp columns; updates/inserts
 * disable automatic timestamp maintenance.
 */
class ParameterCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->user()->can('viewAny', ParameterCategory::class) ?: abort(403);

        $categories = ParameterCategory::query()
            ->withCount('parameters')
            ->orderBy('display_order')
            ->paginate($request->query('per_page', 25));

        return response()->json($categories);
    }

    public function show(ParameterCategory $parameterCategory): JsonResponse
    {
        $parameterCategory->load('parameters');
        return response()->json($parameterCategory);
    }

    public function store(StoreParameterCategoryRequest $request): JsonResponse
    {
        $category = new ParameterCategory();
        $category->timestamps = false;
        $category->fill($request->validated());
        $category->save();

        AuditLogger::record('parameter_category.created', $category, null, AuditLogger::sanitize($category->getAttributes()));

        return response()->json($category->fresh(), 201);
    }

    public function update(UpdateParameterCategoryRequest $request, ParameterCategory $parameterCategory): JsonResponse
    {
        $parameterCategory->timestamps = false;
        $oldValues = AuditLogger::sanitize($parameterCategory->getAttributes());
        $parameterCategory->fill($request->validated());
        $parameterCategory->save();

        AuditLogger::record('parameter_category.updated', $parameterCategory, $oldValues, AuditLogger::sanitize($parameterCategory->getAttributes()));

        return response()->json($parameterCategory->fresh());
    }
}