<?php

namespace App\Modules\Backoffice\Domain\Models;

use App\Support\Database\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #173. Precedente de forma: `App\Modules\Core\Domain\Models\
 * UserInvitation` — pero de plataforma (sin `tenant_id`, sin scope de
 * tenant, sin `TenantModel`), como el resto de `App\Modules\Backoffice\
 * Domain\Models`.
 *
 * No implementa `Auditable`/`RecordsAuditTrail` (ADR-035), por el mismo
 * motivo que `PlatformAdmin`: esa maquinaria escribe en `audit_logs`, que
 * es tabla de tenant. El rastro lo deja explícitamente el servicio que
 * escribe, en `admin_action_logs`.
 *
 * @mixin IdeHelperPlatformAdminInvitation
 */
class PlatformAdminInvitation extends Model
{
    use HasPublicId;

    protected $connection = 'pgsql_platform';

    protected $table = 'platform_admin_invitations';

    protected $fillable = [
        'platform_admin_id',
        'token_hash',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }

    /**
     * Mismo criterio derivado que `UserInvitation::status()` (`REQ-CORE`,
     * api.md §4): nunca una columna.
     */
    public function isLive(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && ! $this->expires_at->isPast();
    }
}
