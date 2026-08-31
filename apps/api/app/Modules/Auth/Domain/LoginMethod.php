<?php

namespace App\Modules\Auth\Domain;

/**
 * datos.md §E.3.1. Vía de acceso de un intento de `login_attempts`,
 * ortogonal a `outcome` (§E.3.1: "el producto cartesiano de dos
 * dimensiones metido en un enumerado" es justo lo que esta columna
 * evita).
 */
enum LoginMethod: string
{
    case Local = 'local';
    case Google = 'google';
}
