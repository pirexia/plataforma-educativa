<?php

namespace App\Modules\Auth\Domain;

/**
 * `datos.md §F.2`, `funcional.md §F.5.1`. Lista blanca cerrada de tres
 * valores para el *claim* del correo de emparejamiento — nunca un nombre
 * de *claim* libre: un campo libre permitiría a un administrador de
 * centro dirigir la comparación hacia un *claim* que él controla.
 */
enum EmailClaim: string
{
    case Email = 'email';
    case PreferredUsername = 'preferred_username';
    case Upn = 'upn';
}
