<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Application\ComputeEffectivePermissions;
use App\Support\Api\ApiException;
use App\Support\Api\Rules\QueryBoolean;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-PERM/funcional.md §7.10-§7.11, api.md §7 (`RPERM-009`). Dos rutas,
 * un solo controlador y un solo cálculo (`ComputeEffectivePermissions`):
 * la diferencia está entera en cómo se autoriza cada una.
 *
 * `show()`: `GET /users/{public_id}/effective-permissions`, de
 * administración — el permiso `permiso_efectivo.leer` ya lo comprueba la
 * ruta (`RequirePermission`). `mine()`: `GET /me/effective-permissions`,
 * autoservicio **por identidad**, sin permiso — el sujeto sale de la
 * sesión y entra en la consulta, nunca de un parámetro (`RN-PERM-23`).
 */
class EffectivePermissionsController extends Controller
{
    public function __construct(
        private readonly ComputeEffectivePermissions $compute,
    ) {}

    public function show(Request $request, string $publicId): JsonResponse
    {
        $subject = User::with(['person', 'roles'])->where('public_id', $publicId)->first();

        if ($subject === null) {
            throw ApiException::notFound();
        }

        return $this->respond($request, $subject);
    }

    public function mine(Request $request): JsonResponse
    {
        $subject = $request->user();

        if (! $subject instanceof User) {
            throw ApiException::unauthenticated();
        }

        $subject->loadMissing(['person', 'roles']);

        return $this->respond($request, $subject);
    }

    private function respond(Request $request, User $subject): JsonResponse
    {
        $request->validate([
            'module_code' => ['sometimes', 'string'],
            'resource' => ['sometimes', 'string'],
            'include_inert' => ['sometimes', new QueryBoolean],
        ]);

        $moduleCodes = $request->filled('module_code')
            ? explode(',', (string) $request->string('module_code'))
            : null;

        $resources = $request->filled('resource')
            ? explode(',', (string) $request->string('resource'))
            : null;

        $includeInert = $request->has('include_inert') ? $request->boolean('include_inert') : true;

        return response()->json([
            'data' => $this->compute->rows($subject, $moduleCodes, $resources, $includeInert),
            'meta' => [
                'subject' => [
                    'public_id' => $subject->public_id,
                    'display_name' => trim($subject->person->given_name.' '.$subject->person->family_name_1),
                ],
                'roles' => $subject->roles->map(fn ($role) => [
                    'public_id' => $role->public_id,
                    'code' => $role->code,
                    'name' => $role->name ?? __($role->name_key),
                ])->values()->all(),
                'computed_at' => now()->toJSON(),
            ],
        ]);
    }
}
