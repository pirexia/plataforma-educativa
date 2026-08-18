<?php

namespace App\Models;

use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * ADR-034 §5: dato del tenant, con RLS. Ausencia de fila = módulo
 * desactivado (falla en cerrado) — comprobarlo es responsabilidad del
 * middleware EnsureModuleEnabled (1.1/1.6), no de este modelo.
 */
class ModuleSubscription extends TenantModel
{
    use HasPublicId;

    protected $fillable = [
        'module_code',
        'enabled',
        'enabled_at',
        'disabled_at',
        'reason',
        'settings',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
        'settings' => 'array',
    ];
}
