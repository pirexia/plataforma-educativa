<?php

namespace App\Modules\Auth\Domain;

/**
 * `datos.md §F.2`, `funcional.md §F.3.2`. De dónde salen los *claims* de
 * identidad: del `id_token` (por defecto, sin llamada adicional) o de
 * `userinfo` (conmutador explícito, no respaldo silencioso — hace falta
 * para Entra ID sin `email` en el `id_token`).
 */
enum ClaimsSource: string
{
    case IdToken = 'id_token';
    case Userinfo = 'userinfo';
}
