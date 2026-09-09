<?php

namespace App\Modules\Backoffice\Domain;

/**
 * datos.md §2.1. Vocabulario cerrado de `platform_admins.status`.
 */
enum PlatformAdminStatus: string
{
    case Activo = 'activo';
    case Suspendido = 'suspendido';
}
