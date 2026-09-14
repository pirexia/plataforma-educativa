<?php

namespace App\Modules\Backoffice\Domain\Models;

use App\Support\Database\HasPublicId;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-BO-001, datos.md §5, ADR-047 §4.1/§4.2/§4.3/§4.4. Historia de la
 * máquina de estados de un centro: dato de negocio que la aplicación lee
 * (cuándo vence la gracia, desde cuándo está suspendido), no auditoría —
 * por eso es tabla distinta de `admin_action_logs` (datos.md §5.1).
 *
 * Append-only, como `AdminActionLog`: no hay `update()`/`delete()` que
 * valga desde este modelo — el motor ya lo rechaza (`REVOKE UPDATE,
 * DELETE`) bajo `FORCE ROW LEVEL SECURITY` sin política permisiva de
 * escritura.
 *
 * @mixin IdeHelperTenantLifecycleEvent
 */
class TenantLifecycleEvent extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $fillable = [
        'public_id',
        'affected_tenant_id',
        'from_status',
        'to_status',
        'reason',
        'occurred_at',
        'performed_by',
        'dual_authorization_id',
        'grace_period_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => TenantStatus::class,
            'to_status' => TenantStatus::class,
            'occurred_at' => 'datetime',
            'grace_period_ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function affectedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'affected_tenant_id');
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'performed_by');
    }

    /**
     * @return BelongsTo<DualAuthorization, $this>
     */
    public function dualAuthorization(): BelongsTo
    {
        return $this->belongsTo(DualAuthorization::class, 'dual_authorization_id');
    }
}
