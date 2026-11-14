<?php

namespace App\Modules\Auth\Domain;

/**
 * `datos.md §G.2.1` (REQ-AUTH-004, 1.4c). El discriminador de
 * `identity_providers`. Inmutable tras el alta (`RN-AUTH-114`) — eso no
 * lo impone un `CHECK` (no ve el valor anterior), lo impone el servicio
 * (`CA-AUTH-316`).
 */
enum Protocol: string
{
    case Oidc = 'oidc';
    case Saml = 'saml';
}
