<?php

namespace App\Modules\Auth\Domain;

use App\Models\User;

/**
 * funcional.md §C.4.7, §C.9.2. La única función del sistema que decide si
 * alguien está obligado a MFA (RN-AUTH-62): el muro (`RequireMfaEnrollment`),
 * el login (`§C.4.4`) y, cuando llegue 1.6, `REQ-BO-007` la consumen sin
 * replicar su lógica (funcional.md §C.12).
 *
 * No se cachea entre peticiones (`§C.4.7` último párrafo, operacion.md
 * §C.7): un rol puede cambiar, una excepción puede revocarse y un factor
 * puede darse de alta en la petición anterior.
 */
interface MfaPolicy
{
    public function resolve(User $user): MfaObligation;

    /**
     * ¿Tiene el usuario al menos un factor confirmado cuyo método admite
     * el tenant hoy? Es el criterio que decide si el login abre un
     * desafío (funcional.md §C.4.4), independiente de si algún rol suyo
     * exige MFA — el MFA voluntario también se verifica.
     */
    public function hasUsableFactor(User $user): bool;

    /**
     * `§C.4.7` punto 1. Usada directamente por `EloquentMfaComplianceDirectory`
     * para la vista previa (`CA-AUTH-136`): una excepción viva exime
     * también en la hipótesis de un `mfa_required` que aún no se ha
     * guardado — es del usuario, no del rol que se está previsualizando.
     */
    public function hasLiveExemption(User $user): bool;

    /**
     * Códigos de los roles vivos del usuario que llevan `mfa_required = true`
     * (RPERM-007, RN-AUTH-62). Vacío si ninguno.
     *
     * @return list<string>
     */
    public function requiredByRoleCodes(User $user): array;

    /**
     * funcional.md §C.4.8: materializa la fila de `user_mfa_obligations`
     * del usuario si está obligado, sin factor utilizable, sin excepción
     * viva y sin obligación ya abierta. Idempotente (garantizado por el
     * índice único parcial de datos.md §C.5, no por esta comprobación).
     * Sin efecto si el usuario no está obligado.
     */
    public function materialize(User $user, MfaObligationTrigger $trigger): void;
}
