<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §C.1.1 punto 9. Resultado de `MfaComplianceDirectory`.
 * `preview` distingue una respuesta hipotética (`§C.4.7`, vista previa) de
 * una consulta del estado real.
 */
final class MfaComplianceSummary
{
    public function __construct(
        public readonly string $rolePublicId,
        public readonly string $roleCode,
        public readonly bool $mfaRequired,
        public readonly bool $preview,
        public readonly int $usersTotal,
        public readonly int $usersEnrolled,
        public readonly int $usersObligated,
        public readonly int $usersInGrace,
        public readonly int $usersEnforced,
        public readonly int $usersExempt,
    ) {}
}
