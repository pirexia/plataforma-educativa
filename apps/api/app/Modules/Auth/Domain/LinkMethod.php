<?php

namespace App\Modules\Auth\Domain;

/**
 * datos.md §E.2. Por qué existe un vínculo. Dos valores y no tres: el
 * `alta` no existe porque el login federado no crea usuarios
 * (`RN-AUTH-99`, `OPEN-AUTH-31` resuelta el 2026-08-31).
 */
enum LinkMethod: string
{
    case FusionAutomatica = 'fusion_automatica';
    case Perfil = 'perfil';
}
