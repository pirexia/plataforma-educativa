<?php

namespace App\Modules\Backoffice\Domain\Models;

use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\AdminActionLogActorType;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-BO-007, ADR-033 §7, ADR-036, ADR-047 §4.1/§4.3/§4.4. Solo-anexión
 * permanente: no hay `update()`/`delete()` que valga desde este modelo
 * (RN-BO-29) — el motor ya lo rechaza para `plataforma_platform`
 * (`REVOKE UPDATE, DELETE`), y esto es defensa en profundidad, no la
 * barrera real.
 *
 * Conexión `pgsql_platform` (BYPASSRLS): es como el backoffice lee la
 * tabla entera, incluidas las columnas que `plataforma_app` no tiene
 * concedidas. La consulta del propio centro (`GET
 * /api/v1/platform-actions`, REQ-CORE) NO usa este modelo: corre sobre
 * la conexión `pgsql` del tenant, con las seis columnas del `GRANT` y
 * filtrada por la política `tenant_visibility`, no por `BYPASSRLS`.
 *
 * @mixin IdeHelperAdminActionLog
 */
class AdminActionLog extends Model
{
    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $fillable = [
        'public_id',
        'occurred_at',
        'actor_type',
        'actor_platform_admin_id',
        'affected_tenant_id',
        'subject_type',
        'subject_id',
        'subject_public_id',
        'action',
        'reason',
        'changes',
        'ip_address',
        'user_agent',
        'request_id',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'actor_type' => AdminActionLogActorType::class,
            'action' => AdminActionLogAction::class,
            'changes' => 'array',
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'actor_platform_admin_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function affectedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'affected_tenant_id');
    }
}
