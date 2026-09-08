<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * RoleController — read-only catalog of the 11 business roles
 * (revenue_officer, technical_manager, …). Live `roles` is a reference table:
 * it is NOT FK-linked to users.role (an enum admin/user), so roles are listed
 * for UI pickers/reporting but never mutated through this API.
 */
class RoleController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $roles = Role::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term));
            })
            ->orderBy('name')
            ->get();

        return JsonResource::collection($roles);
    }

    public function show(Role $role): JsonResource
    {
        return new JsonResource($role);
    }
}