<?php

namespace App\Modules\Acad\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\UnauthorizedException;

/**
 * Base de los controladores de la estructura académica. Las rutas ya llevan
 * `permission:` (que responde `401` sin sesión), así que el usuario existe.
 */
abstract class StructureController extends Controller
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
