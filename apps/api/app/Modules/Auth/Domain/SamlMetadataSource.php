<?php

namespace App\Modules\Auth\Domain;

/**
 * `datos.md §G.3` (REQ-AUTH-004, 1.4c). Conmutador explícito, no deducido
 * de qué columna está informada — mismo criterio que `ClaimsSource` en
 * `§F.2`.
 */
enum SamlMetadataSource: string
{
    case Url = 'url';
    case Xml = 'xml';
}
