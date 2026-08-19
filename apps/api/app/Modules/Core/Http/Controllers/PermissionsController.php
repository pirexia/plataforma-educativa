<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\Permission;
use App\Support\Api\Rules\QueryBoolean;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * api.md §5. Catálogo de plataforma (sin tenant_id, sin paginación por
 * página en el esquema de api.md — se devuelve completo, es una tabla de
 * referencia pequeña).
 */
class PermissionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'module_code' => ['sometimes', 'string'],
            'resource' => ['sometimes', 'string'],
            'include_retired' => ['sometimes', new QueryBoolean],
        ]);

        $query = Permission::query()->orderBy('code');

        if (! $request->boolean('include_retired')) {
            $query->whereNull('retired_at');
        }

        if ($request->filled('module_code')) {
            $query->where('module_code', $request->string('module_code')->value());
        }

        if ($request->filled('resource')) {
            $query->where('resource', $request->string('resource')->value());
        }

        return response()->json([
            'data' => $query->get(['code', 'resource', 'action', 'module_code', 'is_special_category', 'retired_at']),
        ]);
    }
}
