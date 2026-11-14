<?php

namespace App\Modules\FamUnit\Infrastructure;

use App\Models\User;
use App\Modules\FamUnit\Application\TermsAcceptanceService;
use App\Support\Sessions\SessionWall;

/**
 * ADR-067 §5.7, REQ-FAM-UNIT/funcional.md §4.10 y §7.5 (1.14-c, `REQ-FAM-UNIT-003`): el muro de condiciones de uso, el
 * segundo muro de sesión (detrás del de alta de MFA, L6). Un tutor sin aceptación exigible de las condiciones vigentes
 * tiene sesión pero solo alcanza la lista exenta de abajo (L3); con el MFA también pendiente manda el muro de MFA
 * (`ADR-067 §5.4`).
 *
 * Solo deniega: que una ruta esté exenta no la autoriza (`INV-002`). `blocks()` es **una sola consulta** y no se
 * cachea entre peticiones (`ADR-067 §5.8`); con el módulo `fam_unit` no contratado, `RequireSessionWalls` ni siquiera
 * evalúa el muro (`§5.9`). Los tutores son los únicos obligados (`RN-FAM-UNIT-61`).
 */
final class TermsAcceptanceWall implements SessionWall
{
    /**
     * L3 (ADR-067 §6): la propuesta de `api.md §2.8` más `auth.csrf-cookie` (sin ella la SPA no puede renovar el *token*
     * CSRF para el `POST` de aceptación). **No** incluye `core.me.update` (`OPEN-067-04`). Con la herencia de exenciones
     * del muro de MFA (`§5.4`), la lista **efectiva** suma `GET /auth/mfa`, `POST /auth/mfa-enrollments` y
     * `POST /auth/mfa-factors`.
     *
     * @var list<string>
     */
    public const EXEMPT_ROUTE_NAMES = [
        'core.me.show',
        'fam-unit.me-terms.show',
        'fam-unit.me-terms-acceptances.store',
        'auth.session.destroy',
        'auth.csrf-cookie',
        'core.tenant.branding',
    ];

    public function __construct(private readonly TermsAcceptanceService $acceptances) {}

    public function code(): string
    {
        return 'terms-acceptance';
    }

    public function errorType(): string
    {
        return 'terms-acceptance-required';
    }

    public function moduleCode(): string
    {
        return 'fam_unit';
    }

    public function exemptRouteNames(): array
    {
        return self::EXEMPT_ROUTE_NAMES;
    }

    public function blocks(User $user): bool
    {
        return $this->acceptances->isPending($user);
    }
}
