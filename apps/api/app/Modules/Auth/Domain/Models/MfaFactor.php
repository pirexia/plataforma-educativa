<?php

namespace App\Modules\Auth\Domain\Models;

use App\Models\User;
use App\Modules\Auth\Domain\MfaMethod;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §C.2. Un solo tipo de fila para el alta provisional
 * (`confirmed_at NULL`) y para el factor confirmado — la misma entidad en
 * un estado anterior, igual que una invitación no aceptada en `REQ-CORE`.
 *
 * Selective: `secret_encrypted` se declara secreto explícitamente además
 * de encajar en el patrón global `*secret*` (mismo argumento que `§B.2`
 * con `session_id`: depender solo de una coincidencia de nombre es
 * depender de una coincidencia). `last_used_step`/`last_used_at` no se
 * registran: cambian en cada login y no dicen nada que `login` no diga ya.
 *
 * `code_hash`/`code_expires_at` (datos.md §D.2, 1.3b): hash SHA-256 y
 * caducidad del código de un alta de método de entrega (`email`),
 * distinta de `expires_at` (caducidad del alta). Se ponen a `NULL` al
 * confirmar, en la misma transacción (`RN-AUTH-75`). `code_hash` se
 * declara secreto a mano: el patrón global `*secret*` no cubre su nombre.
 *
 * @mixin IdeHelperMfaFactor
 */
class MfaFactor extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'user_mfa_factors';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'method', 'confirmed_at', 'is_preferred', 'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = ['secret_encrypted', 'code_hash'];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    protected $fillable = [
        'user_id',
        'method',
        'secret_encrypted',
        'code_hash',
        'code_expires_at',
        'last_used_step',
        'confirmed_at',
        'expires_at',
        'confirmation_attempts',
        'last_used_at',
        'is_preferred',
    ];

    protected $casts = [
        'method' => MfaMethod::class,
        // RN-AUTH-55: cifrado con APP_KEY, nunca en claro en la base de
        // datos. Es la primera columna cifrada en reposo del producto.
        'secret_encrypted' => 'encrypted',
        'code_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'is_preferred' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isEnrollmentExpired(): bool
    {
        return ! $this->isConfirmed() && $this->expires_at !== null && now()->greaterThanOrEqualTo($this->expires_at);
    }
}
