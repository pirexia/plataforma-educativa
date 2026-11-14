<?php

namespace App\Modules\Auth\Domain;

/**
 * datos.md §E.2, ampliado en datos.md §F.4 (1.4b). Por qué existe un
 * vínculo. `EmparejamientoSso` es propio del camino institucional
 * (`RN-AUTH-106`): la confianza no viene de un `email_verified` como
 * `FusionAutomatica`, viene de que el centro catalogó ese emisor. El
 * `alta` sigue sin existir: ningún camino crea usuarios (`RN-AUTH-108`).
 */
enum LinkMethod: string
{
    case FusionAutomatica = 'fusion_automatica';
    case Perfil = 'perfil';
    case EmparejamientoSso = 'emparejamiento_sso';
}
