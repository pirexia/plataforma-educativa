<?php

namespace App\Modules\Auth\Domain;

/**
 * datos.md §E.3.1, ampliado en datos.md §F.5 (1.4b). Vía de acceso de un
 * intento de `login_attempts`, ortogonal a `outcome` (§E.3.1: "el
 * producto cartesiano de dos dimensiones metido en un enumerado" es justo
 * lo que esta columna evita). `Sso` es un solo valor para **todo** el SSO
 * institucional, no el identificador del proveedor concreto — esa
 * pregunta la responde `user_identities.identity_provider_id`, que no
 * caduca a los 90 días de esta tabla (`§F.5`).
 */
enum LoginMethod: string
{
    case Local = 'local';
    case Google = 'google';
    case Sso = 'sso';
}
