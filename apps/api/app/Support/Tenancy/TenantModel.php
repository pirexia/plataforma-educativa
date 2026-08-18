<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ADR-033 §4: base obligatoria de todo modelo de negocio (INV-001).
 * withoutGlobalScope(TenantScope::class) queda prohibido en app/Modules/**
 * — verificado por un test de arquitectura en 0.7.11, no por este fichero.
 *
 * SoftDeletes desde ADR-034 §6: tenantTable() ya crea la columna
 * deleted_at, pero sin este trait un delete() la ignoraba y borraba la
 * fila físicamente — pérdida de datos silenciosa que incumplía INV-004
 * desde antes de que existiera ninguna entidad de negocio real. Un modelo
 * que de verdad necesite borrado físico se declara explícitamente en
 * config('tenancy.hard_delete_models') (vacío por ahora: ninguno lo
 * necesita todavía) en vez de dejar de usar este trait en silencio.
 */
abstract class TenantModel extends Model
{
    use BelongsToTenant;
    use RecordsAuthorship;
    use SoftDeletes;

    public function getConnectionName(): ?string
    {
        if (app(TenantContext::class)->isPlatformMode()) {
            return 'pgsql_platform';
        }

        return parent::getConnectionName();
    }
}
