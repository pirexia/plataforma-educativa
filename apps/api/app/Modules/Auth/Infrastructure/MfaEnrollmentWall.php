<?php

namespace App\Modules\Auth\Infrastructure;

use App\Models\User;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Support\Sessions\SessionWall;

/**
 * ADR-067 §5.6, REQ-AUTH/funcional.md §C.4.9: el muro de alta de MFA como un muro de sesión registrado (antes,
 * `RequireMfaEnrollment`), **sin cambiar su comportamiento observable**. Un usuario **obligado** (`MfaPolicy::resolve()`
 * ⇒ `Exigible`, gracia vencida y sin factor) tiene sesión pero solo alcanza la lista blanca de abajo (`INV-002`: lista
 * blanca, no negra).
 *
 * `MfaPolicy` no se toca. Se inyecta por el contenedor en cada petición (`RequireSessionWalls` resuelve el muro por
 * petición, ADR-067 §5.2): es `scoped()` y `resolve()` tiene efectos (materializa la obligación), de ahí que el
 * atajo de exención del *middleware* vaya antes de `blocks()`.
 */
final class MfaEnrollmentWall implements SessionWall
{
    /**
     * L4 (ADR-067 §6): sin añadir ni quitar ninguno. `DELETE /auth/session` siempre permitido (un muro sin salida es un
     * secuestro); `POST /auth/password-changes` **no** (el usuario obligado completa su alta o se va, no reorganiza su
     * cuenta).
     *
     * @var list<string>
     */
    public const EXEMPT_ROUTE_NAMES = [
        'core.me.show',
        'auth.mfa.show',
        'auth.mfa-enrollments.store',
        'auth.mfa-factors.store',
        'auth.session.destroy',
        'auth.csrf-cookie',
        'core.tenant.branding',
    ];

    public function __construct(private readonly MfaPolicy $policy) {}

    public function code(): string
    {
        return 'mfa-enrollment';
    }

    public function errorType(): string
    {
        return 'mfa-enrollment-required';
    }

    public function moduleCode(): string
    {
        return 'auth';
    }

    public function exemptRouteNames(): array
    {
        return self::EXEMPT_ROUTE_NAMES;
    }

    public function blocks(User $user): bool
    {
        return $this->policy->resolve($user)->isEnforced();
    }
}
