<?php

namespace App\Modules\Core\Domain\Models;

use App\Support\Database\HasPublicId;
use App\Support\FeatureFlags\FeatureFlagScopeType;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `datos.md §9.3`. Mismo criterio de acceso que `FeatureFlag`
 * (`RN-BO-99`, `CA-BO-169`): sólo se usa dentro de `app/Modules/Core`.
 *
 * Borrado lógico (`RN-BO-104`): una regla retirada conserva su fila con
 * `deleted_at` y sigue siendo consultable — es la prueba de a qué
 * centros se expuso qué y cuándo (`datos.md §13`).
 */
class FeatureFlagRule extends Model
{
    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'feature_flag_id',
        'scope_type',
        'affected_tenant_id',
        'role_code',
        'percentage',
        'enabled',
        'reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'scope_type' => FeatureFlagScopeType::class,
        'enabled' => 'boolean',
        'percentage' => 'integer',
    ];

    /**
     * @return BelongsTo<FeatureFlag, $this>
     */
    public function flag(): BelongsTo
    {
        return $this->belongsTo(FeatureFlag::class, 'feature_flag_id');
    }

    /**
     * `datos.md §9.3`: referencia, no propiedad — mismo criterio que
     * `affected_tenant_id` de `admin_action_logs` (§4.3, `ADR-047`).
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'affected_tenant_id');
    }

    /**
     * Mismo motivo que `FeatureFlag::getConnectionName()`: sin
     * `tenant_id` propio, conmuta a `plataforma_platform` dentro de
     * `runAsPlatform()` — aquí, a diferencia de `feature_flags`, el
     * backoffice conserva `INSERT`/`UPDATE`/`DELETE` completos
     * (`datos.md §9.6`: sólo `feature_flags` recibe el `REVOKE` por
     * columnas).
     */
    public function getConnectionName(): ?string
    {
        if (app(TenantContext::class)->isPlatformMode()) {
            return 'pgsql_platform';
        }

        return parent::getConnectionName();
    }
}
