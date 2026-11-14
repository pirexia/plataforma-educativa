<?php

namespace App\Modules\Auth\Domain\Models;

use App\Models\User;
use App\Modules\Auth\Domain\ClientDeviceType;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §B.2. Complementaria de `sessions` del framework
 * (funcional.md §B.2.2): `session_id` es la credencial portadora del
 * navegador y NUNCA sale por la API, por eso se declara explícitamente
 * como secreto — no lo cubre ningún patrón automático de
 * `config('audit.secret_attribute_patterns')` (no contiene "token" ni
 * "password" ni "secret"). `ip_address`/`user_agent`/`location_label` se
 * redactan como `identifier` por no estar en la lista de inclusión.
 *
 * `auditExcludedEvents(): ['created']` — ADR-040 §4.3: el nacimiento de
 * la fila, siempre dentro de la transacción del login (funcional.md
 * §B.4.1), ya lo registra el evento `login` sobre `User` (ADR-039). El
 * resto del ciclo de vida (revocación, los siete cierres de §B.4.6,
 * borrado lógico) se sigue auditando entero por el mecanismo automático.
 *
 * @mixin IdeHelperUserSession
 */
class UserSession extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'user_sessions';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'started_at', 'ended_at', 'end_reason', 'ended_by', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = ['session_id'];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /**
     * ADR-040 §4.3/§4.4: el login es el único productor de filas de
     * `user_sessions` (ADR-040 §1.3) — si un paso futuro crea una fila
     * fuera de ese flujo, este ADR debe sustituirse, no ampliarse por
     * analogía. `AuditExcludedEventsArchitectureTest` fija que ningún otro
     * modelo del repositorio declara exclusión alguna.
     *
     * @return array<int, string>
     */
    public function auditExcludedEvents(): array
    {
        return ['created'];
    }

    protected $fillable = [
        'user_id',
        'session_id',
        'started_at',
        'ip_address',
        'user_agent',
        'client_browser',
        'client_platform',
        'client_device_type',
        'location_label',
        'known_device_id',
        'ended_at',
        'end_reason',
        'ended_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'end_reason' => SessionEndReason::class,
        'client_device_type' => ClientDeviceType::class,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<UserKnownDevice, $this>
     */
    public function knownDevice(): BelongsTo
    {
        return $this->belongsTo(UserKnownDevice::class, 'known_device_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function endedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function isLive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * ADR-040 §6 (la trampa de la revocación masiva): cierra esta fila
     * modelo a modelo, nunca por `UPDATE` masivo — un `UPDATE` por
     * consulta no dispara el observer de auditoría y la revocación
     * quedaría sin registrar (CA-AUTH-102).
     */
    public function close(SessionEndReason $reason, ?User $endedBy = null): void
    {
        $this->ended_at = now();
        $this->end_reason = $reason;
        $this->ended_by = $endedBy?->id;
        $this->save();
    }
}
