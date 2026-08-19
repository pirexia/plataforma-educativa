<?php

namespace App\Models;

use App\Support\Tenancy\TenantModel;

/**
 * ADR-034 §2: concesión de un permiso a un rol del tenant. Sin política de
 * auditoría propia en 1.1 — en este paso solo la siembra
 * `tenant:provision-defaults`, no hay escritura de usuario que auditar
 * todavía (1.5 la traerá junto con el resolutor completo).
 */
class PermissionRole extends TenantModel
{
    protected $table = 'permission_role';

    protected $fillable = [
        'role_id',
        'permission_code',
        'effect',
        'scope',
    ];
}
