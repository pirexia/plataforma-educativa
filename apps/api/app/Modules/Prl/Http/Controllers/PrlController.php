<?php

namespace App\Modules\Prl\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\UnauthorizedException;

/**
 * Base de los controladores de `prl`. Las rutas ya llevan `permission:` (que responde `401` sin sesión), así que el usuario
 * existe. Los permisos de `prl` son todos de ámbito `todos` (`permisos.md §8.2`): ninguna consulta se acota por ámbito.
 */
abstract class PrlController extends Controller
{
    protected function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new UnauthorizedException;
        }

        return $user;
    }
}
