<?php

namespace App\Modules\Core\Domain\Models;

use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `datos.md §9.2`. **`RN-BO-99`, `CA-BO-169`: este modelo sólo se usa
 * dentro de `app/Modules/Core`.** Ningún otro módulo del producto —
 * incluido `Backoffice`— lee ni escribe `feature_flags` por modelo o por
 * consulta: se consume por `App\Support\FeatureFlags\FeatureFlagEvaluator`
 * /`FeatureFlagExplainer` (evaluación) o por
 * `App\Modules\Core\Domain\FeatureFlagAdministration` (catálogo y
 * escritura administrativa), nunca directamente.
 *
 * Sin `SoftDeletes`: el catálogo **nunca borra** (`RN-BO-44`), se marca
 * `retired_at` — el mismo patrón que `Module` (`datos.md §9.2`, `ADR-034
 * §5`).
 */
class FeatureFlag extends Model
{
    use HasPublicId;

    protected $fillable = [
        'key',
        'module_code',
        'name_key',
        'description_key',
        'rollout_unit',
        'status',
        'status_reason',
    ];

    protected $casts = [
        'rules_version' => 'integer',
        'retired_at' => 'datetime',
    ];

    /**
     * @return HasMany<FeatureFlagRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(FeatureFlagRule::class);
    }

    /**
     * Sin `tenant_id` (`ADR-033 §7`): no puede heredar de `TenantModel`
     * para conmutar de conexión, pero necesita el mismo comportamiento
     * que `TenantModel::getConnectionName()` — dentro de
     * `runAsPlatform()` (backoffice, `plataforma_platform`, único rol con
     * `UPDATE` sobre `status`/`status_reason`/`rules_version`,
     * `datos.md §9.6`), fuera de él la conexión de tenant por defecto
     * (`pgsql`, rol `plataforma_app`, sólo lectura).
     * `platform:sync-registry` usa `pgsql_owner` explícitamente y no pasa
     * por este modelo (`SyncModuleRegistry`, `DB::connection('pgsql_owner')`).
     */
    public function getConnectionName(): ?string
    {
        if (app(TenantContext::class)->isPlatformMode()) {
            return 'pgsql_platform';
        }

        return parent::getConnectionName();
    }
}
