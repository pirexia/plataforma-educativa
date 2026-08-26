<?php

namespace App\Modules\Auth\Domain;

/**
 * funcional.md §C.4.7. Los tres resultados posibles de `MfaPolicy::resolve()`.
 * Valor interno de dominio — no es el `trigger` de `user_mfa_obligations`
 * (datos.md §C.5) ni ningún enumerado expuesto por la API.
 */
enum MfaObligationState
{
    case NoObligado;
    case EnGracia;
    case Exigible;
}
