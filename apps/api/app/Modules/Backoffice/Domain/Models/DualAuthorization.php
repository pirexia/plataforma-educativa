<?php

namespace App\Modules\Backoffice\Domain\Models;

use App\Modules\Backoffice\Domain\DualAuthorizationAction;
use App\Modules\Backoffice\Domain\DualAuthorizationStatus;
use App\Support\Database\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-BO-007, datos.md §3. La tabla que sostiene "ninguna acción
 * destructiva en un solo paso". La restricción de aprobador distinto
 * (RN-BO-19) vive en el motor (`dual_authorizations_distinct_approver`),
 * no en este modelo — CA-BO-062 la comprueba escribiendo por SQL
 * directo, precisamente para no confiar en que este código la respete.
 *
 * Mecanismo genérico sin *endpoint* propio en 1.6 (docblock de la
 * migración): el motor y su restricción son de este sub-paso; solicitar,
 * aprobar y ejecutar una operación concreta llega con la primera acción
 * real (`tenant.eliminar` en 1.6b).
 *
 * @mixin IdeHelperDualAuthorization
 */
class DualAuthorization extends Model
{
    use HasPublicId;

    protected $connection = 'pgsql_platform';

    protected $fillable = [
        'action',
        'payload',
        'payload_fingerprint',
        'reason',
        'requested_by',
        'requested_at',
        'expires_at',
        'status',
        'approved_by',
        'approved_at',
        'resolution_reason',
        'executed_at',
        'execution_error',
    ];

    protected function casts(): array
    {
        return [
            'action' => DualAuthorizationAction::class,
            'payload' => 'array',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'status' => DualAuthorizationStatus::class,
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'requested_by');
    }

    /**
     * @return BelongsTo<PlatformAdmin, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'approved_by');
    }

    public function isExpired(): bool
    {
        return $this->status === DualAuthorizationStatus::Pendiente && $this->expires_at->isPast();
    }
}
