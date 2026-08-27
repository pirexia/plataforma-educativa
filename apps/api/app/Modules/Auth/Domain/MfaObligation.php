<?php

namespace App\Modules\Auth\Domain;

use Illuminate\Support\Carbon;

/**
 * funcional.md §C.4.7. Resultado de `MfaPolicy::resolve()`. `graceDeadlineAt`
 * viaja también en `Exigible` (ya vencido): es lo que `RequireMfaEnrollment`
 * y el cuerpo de `urn:pge:error:mfa-enrollment-required` necesitan sin
 * volver a consultar la fila de `user_mfa_obligations` (api.md §C.1.1).
 */
final class MfaObligation
{
    private function __construct(
        public readonly MfaObligationState $state,
        public readonly ?Carbon $graceDeadlineAt = null,
    ) {}

    public static function notObligated(): self
    {
        return new self(MfaObligationState::NoObligado);
    }

    public static function inGrace(Carbon $deadline): self
    {
        return new self(MfaObligationState::EnGracia, $deadline);
    }

    public static function enforced(Carbon $deadline): self
    {
        return new self(MfaObligationState::Exigible, $deadline);
    }

    public function isObligated(): bool
    {
        return $this->state !== MfaObligationState::NoObligado;
    }

    public function isEnforced(): bool
    {
        return $this->state === MfaObligationState::Exigible;
    }
}
