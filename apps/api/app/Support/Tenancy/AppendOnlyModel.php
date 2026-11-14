<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * ADR-034 §3, §6: contraparte en PHP del REVOKE UPDATE, DELETE que aplica
 * tenantTableAppendOnly() en el motor. El error salta en desarrollo con un
 * mensaje claro en vez de como un fallo de privilegios inesperado en
 * producción — la barrera real es el REVOKE, esto es solo que falle rápido
 * y de forma legible antes de llegar allí.
 *
 * Sin timestamps de Eloquent: estas tablas no tienen created_at/updated_at
 * (tenantTableAppendOnly() no los añade), usan occurred_at u otro campo
 * propio del dominio.
 */
abstract class AppendOnlyModel extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException(static::class.' es append-only: no se puede modificar (ADR-034 §3).');
        });

        static::deleting(function (): void {
            throw new LogicException(static::class.' es append-only: no se puede borrar (ADR-034 §3).');
        });
    }

    public function getConnectionName(): ?string
    {
        if (app(TenantContext::class)->isPlatformMode()) {
            return 'pgsql_platform';
        }

        return parent::getConnectionName();
    }
}
