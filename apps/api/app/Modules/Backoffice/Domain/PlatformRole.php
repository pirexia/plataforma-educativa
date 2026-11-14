<?php

namespace App\Modules\Backoffice\Domain;

/**
 * REQ-BO-007, permisos.md §2. Los cuatro roles internos, fijos y
 * declarados en código — no hay requisito que pida roles de plataforma
 * personalizados, así que no hay tabla de catálogo (a diferencia de
 * `REQ-PERM`, donde el centro compone los suyos).
 */
enum PlatformRole: string
{
    case Soporte = 'soporte';
    case Operaciones = 'operaciones';
    case Comercial = 'comercial';
    case Superadministrador = 'superadministrador';
}
