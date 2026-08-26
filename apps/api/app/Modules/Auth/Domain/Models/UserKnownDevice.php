<?php

namespace App\Modules\Auth\Domain\Models;

use App\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §B.1. Selective: `device_token_hash` lo redacta automáticamente
 * el patrón global `*token*` sin declararlo; `last_ip_address` se redacta
 * como `identifier` por no estar en la lista de inclusión (paso 3 de
 * `AuditChangeBuilder`). Sin `public_id` (§B.1): ningún endpoint de 1.2b
 * devuelve un dispositivo como recurso propio, viaja dentro de la sesión.
 * El alta (`created`) y el aviso (`updated` con `alerted_at`) quedan
 * auditados sin ninguna llamada manual (funcional.md §B.10) — a diferencia
 * de `UserSession`, este modelo no declara `auditExcludedEvents()`.
 *
 * @mixin IdeHelperUserKnownDevice
 */
class UserKnownDevice extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use RecordsAuditTrail;

    protected $table = 'user_known_devices';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'first_seen_at', 'last_seen_at', 'login_count', 'alerted_at', 'label', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    protected $fillable = [
        'user_id',
        'device_token_hash',
        'first_seen_at',
        'last_seen_at',
        'login_count',
        'label',
        'last_ip_address',
        'alerted_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'alerted_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
