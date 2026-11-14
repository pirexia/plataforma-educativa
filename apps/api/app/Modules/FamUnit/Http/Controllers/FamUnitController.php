<?php

namespace App\Modules\FamUnit\Http\Controllers;

use App\Models\User;
use App\Support\Authorization\PermissionDecision;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\UnauthorizedException;

/**
 * Base de los controladores de `fam_unit`. Las rutas ya llevan `permission:` (que responde `401` sin sesión), así que
 * el usuario y la `PermissionDecision` existen.
 */
abstract class FamUnitController extends Controller
{
    protected function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new UnauthorizedException;
        }

        return $user;
    }

    /**
     * La decisión del permiso de la ruta, que deja `RequirePermission`: se aplica con `ScopedQuery` (`ADR-044 §4.2`).
     * Si falta, fallo: nunca se lee sin acotar por ámbito.
     */
    protected function decision(Request $request): PermissionDecision
    {
        $decision = $request->attributes->get('permission_decision');

        if (! $decision instanceof PermissionDecision) {
            throw new \LogicException('Falta la decisión de permiso de la ruta.');
        }

        return $decision;
    }
}
