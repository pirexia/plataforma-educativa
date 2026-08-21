<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-034 §5, §7: catálogo de plataforma, no dato del tenant. Mismo
 * patrón que Permission: sin tenant_id, sin RLS, escritura reservada al
 * comando `platform:sync-registry` (REVOKE en la migración de 0.8.7).
 *
 * @mixin IdeHelperModule
 */
class Module extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name_key',
        'phase',
        'retired_at',
    ];

    protected $casts = [
        'retired_at' => 'datetime',
    ];
}
