<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreParameterRequest;
use App\Http\Requests\UpdateParameterRequest;
use App\Models\Parameter;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parameters API — read (any authenticated) + admin write.
 *
 * Parameters are reference data tied (loosely) to parameter_categories.
 * The live table has no timestamp columns, so updates/inserts disable the
 * framework's automatic timestamp maintenance.
 */
class ParameterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->user()->can('viewAny', Parameter::class) ?: abort(403);

        $query = Parameter::query()->with('category');

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }

        $parameters = $query->orderBy('code')->paginate($request->query('per_page', 25));

        return response()->json($parameters);
    }

    public function show(Parameter $parameter): JsonResponse
    {
        $parameter->load('category');
        return response()->json($parameter);
    }

    public function store(StoreParameterRequest $request): JsonResponse
    {
        $parameter = new Parameter();
        $parameter->timestamps = false; // live table lacks timestamp columns
        $parameter->fill($request->validated());
        $parameter->save();

        AuditLogger::record('parameter.created', $parameter, null, AuditLogger::sanitize($parameter->getAttributes()));

        return response()->json($parameter->fresh(), 201);
    }

    public function update(UpdateParameterRequest $request, Parameter $parameter): JsonResponse
    {
        $parameter->timestamps = false;
        $oldValues = AuditLogger::sanitize($parameter->getAttributes());
        $parameter->fill($request->validated());
        $parameter->save();

        AuditLogger::record('parameter.updated', $parameter, $oldValues, AuditLogger::sanitize($parameter->getAttributes()));

        return response()->json($parameter->fresh());
    }
}