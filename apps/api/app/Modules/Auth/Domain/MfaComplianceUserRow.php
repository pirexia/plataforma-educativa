<?php

namespace App\Modules\Auth\Domain;

use Illuminate\Support\Carbon;

/**
 * api.md §C.5, `GET /mfa-compliance/users`. Restaurado en 1.3 el
 * 2026-08-27 (decisión del usuario tras revisar el hallazgo: un subagente
 * anterior lo había movido a `1.3b` por error, agrupado con la excepción
 * temporal que sí se movió correctamente — `funcional.md §C.16`).
 *
 * Fila individualizada de `MfaComplianceDirectory::listUsers()`. A
 * diferencia de `MfaComplianceSummary` (agregado de un rol), esto describe
 * a **una** persona: solo los campos públicos (`§C.6.1` de `permisos.md`
 * explica por qué este endpoint expone identidad bajo `mfa.leer` y no
 * `usuario.leer`). Nunca lleva secretos, hashes ni recuento de códigos de
 * respaldo restantes.
 *
 * `$state` es uno de `enrolled`, `exempt`, `pending`, `past_deadline` — el
 * mismo vocabulario que `MfaObligationState` (`EnGracia`/`Exigible`) más
 * `enrolled`/`exempt`. `obligated` (que también admite el filtro de
 * `IndexMfaComplianceUsersRequest`) es un alias de conveniencia sobre
 * `pending`+`past_deadline` — el dominio no tiene un tercer estado propio
 * para ese nombre; `MfaObligation::isObligated()` ya es exactamente
 * `pending || past_deadline`. Ninguna fila individual lleva literalmente
 * `state = 'obligated'`.
 */
final class MfaComplianceUserRow
{
    /**
     * @param  list<string>  $enrolledMethods
     * @param  list<string>  $requiredByRoles
     */
    public function __construct(
        public readonly string $userPublicId,
        public readonly string $email,
        public readonly ?string $givenName,
        public readonly ?string $familyName1,
        public readonly ?string $familyName2,
        public readonly string $state,
        public readonly ?Carbon $graceDeadlineAt,
        public readonly array $enrolledMethods,
        public readonly array $requiredByRoles,
    ) {}
}
