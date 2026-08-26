<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Support\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REQ-AUTH/funcional.md §C.4.9. El muro de alta: un usuario **obligado**
 * (`MfaPolicy::resolve()` ⇒ `Exigible`, gracia vencida y sin factor) tiene
 * sesión pero solo alcanza la lista blanca de abajo. `INV-002`: lista
 * blanca, no negra — un endpoint nuevo de cualquier módulo queda
 * bloqueado por defecto, que es el comportamiento correcto.
 *
 * Sin efecto sobre un usuario sin sesión (`Auth::user()` `null`: no hay
 * nada que restringir aquí, cualquier `401` lo decide el endpoint o
 * `permission`) ni sobre uno con factor confirmado (nunca pasa por aquí,
 * pasa por el desafío de `§C.4.4`).
 */
class RequireMfaEnrollment
{
    /**
     * §C.4.9 puntos 1-3: `DELETE /auth/session` siempre permitido (un
     * muro sin salida es un secuestro); `POST /auth/password-changes`
     * **no** está permitido (el usuario obligado completa su alta o se
     * va, no reorganiza su cuenta).
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTE_NAMES = [
        'core.me.show',
        'auth.mfa.show',
        'auth.mfa-enrollments.store',
        'auth.mfa-factors.store',
        'auth.session.destroy',
        'auth.csrf-cookie',
        'core.tenant.branding',
    ];

    public function __construct(
        private readonly MfaPolicy $policy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if (in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return $next($request);
        }

        if (! $this->policy->resolve($user)->isEnforced()) {
            return $next($request);
        }

        throw ApiException::mfaEnrollmentRequired();
    }
}
