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
 * de permisos.md §2.
 *
 * ADR-044 §4.2 (funcional.md §3.3): la puerta y la acotación comparten una
 * sola fuente. Esta es la puerta — ¿el sujeto tiene el permiso con algún
 * ámbito no denegado? — y deja la `PermissionDecision` ya calculada en los
 * atributos de la petición, para que el controlador la use al acotar su
 * consulta (`App\Support\Authorization\ScopedQuery`) sin volver a resolver
 * el permiso una segunda vez.
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

        $decision = $this->resolver->decide($user, $permissionCode);

        if (! $decision->permitted) {
            throw ApiException::forbidden();
        }

        $request->attributes->set('permission_decision', $decision);

        return $next($request);
    }
}
