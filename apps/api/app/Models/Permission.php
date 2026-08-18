<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-034 §2, §7: catálogo de plataforma, no dato del tenant. Modelo
 * plano, no TenantModel — sin tenant_id, sin RLS, sin borrado lógico
 * (retired_at cumple ese papel a su manera: nunca se borra una fila).
 * Escritura reservada al comando de 0.8.11 (REVOKE en la migración, no
 * solo en este modelo).
 */
class Permission extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'resource',
        'action',
        'module_code',
        'is_special_category',
        'retired_at',
    ];

    protected $casts = [
        'is_special_category' => 'boolean',
        'retired_at' => 'datetime',
    ];
}
