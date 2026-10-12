<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * INV-002, REQ-ACAD/api.md (cabecera): «autenticación → `module-enabled` →
 * `permission:`». Responde `401` sin sesión, **antes** de que `module-enabled`
 * pueda responder `403` a un anónimo.
 *
 * No se usa el alias `auth` de Laravel a propósito: `Authenticate` figura en
 * `$middlewarePriority` y `SortedMiddleware` lo coloca **antes** de
 * `resolve-tenant`, de modo que carga el usuario sin tenant activo
 * (`TenantContextMissing`, un `500` en una petición real que los tests con
 * `actingAs()` no ven). Esta clase no está en esa lista: conserva su posición,
 * detrás del grupo `resolve-tenant` → sesión.
 */
class RequireAuthenticatedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            throw ApiException::unauthenticated();
        }

        return $next($request);
    }
}
