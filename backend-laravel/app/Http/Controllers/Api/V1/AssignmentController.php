<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Parameter;
use App\Models\ParameterCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * AssignmentController — manages which parameters and categories (sections)
 * a user may enter data for, via the legacy pivot tables
 * `user_parameter_assignments` / `user_section_assignments`.
 *
 * Both pivots carry DB-level composite unique indexes; syncs also run inside
 * transactions so partial updates never persist.
 */
class AssignmentController extends Controller
{
    /**
     * List a user's parameter + category assignments (self or admin).
     */
    public function index(User $user): JsonResource
    {
        $this->authorize('view', $user);

        return new JsonResource([
            'parameters' => $user->accessibleParameters()->orderBy('parameters.code')->get(),
            'categories' => $user->accessibleCategories()->orderBy('parameter_categories.name')->get(),
        ]);
    }

    /**
     * Replace a user's parameter assignments (admin only).
     * Body: { parameter_ids: int[] }
     */
    public function syncParameters(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $ids = $this->validatedIdList($request, 'parameter_ids', Parameter::class);

        $old = $user->accessibleParameters()->pluck('parameters.id')->all();

        DB::transaction(function () use ($user, $ids) {
            $user->accessibleParameters()->sync($ids);
        });

        AuditLogger::record('user.parameter_assignments.synced', $user,
            ['parameter_ids' => $old], ['parameter_ids' => array_values($ids)]);

        return response()->json([
            'message'        => 'Parameter assignments updated.',
            'parameter_ids'  => array_values($ids),
        ]);
    }

    /**
     * Replace a user's category (section) assignments (admin only).
     * Body: { category_ids: int[] }
     */
    public function syncCategories(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $ids = $this->validatedIdList($request, 'category_ids', ParameterCategory::class);

        $old = $user->accessibleCategories()->pluck('parameter_categories.id')->all();

        DB::transaction(function () use ($user, $ids) {
            $user->accessibleCategories()->sync($ids);
        });

        AuditLogger::record('user.category_assignments.synced', $user,
            ['category_ids' => $old], ['category_ids' => array_values($ids)]);

        return response()->json([
            'message'       => 'Category assignments updated.',
            'category_ids'  => array_values($ids),
        ]);
    }

    /**
     * Validate and return a unique list of existing entity ids.
     *
     * @return int[]
     */
    private function validatedIdList(Request $request, string $field, string $model): array
    {
        $data = $request->validate([
            $field => ['present', 'array'],
            "$field.*" => ['integer', Rule::exists((new $model)->getTable(), 'id')],
        ]);

        return array_values(array_unique(array_map('intval', $data[$field])));
    }
}