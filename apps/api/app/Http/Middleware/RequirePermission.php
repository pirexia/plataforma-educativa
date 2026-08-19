<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\Authorization\PermissionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * INV-002/RPERM-011: sin sesión, 401; sin el permiso, 403 (CA-CORE-070).
 * Se declara en la ruta como `permission:usuario.leer` — el código exacto
 * de permisos.md §2, nunca un ámbito (§5: en 1.1 todo es `todos`, el
 * ámbito no se evalúa).
 */
class RequirePermission
{
    public function __construct(
        private readonly PermissionResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next, string $permissionCode): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        if (! $this->resolver->can($user, $permissionCode)) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
