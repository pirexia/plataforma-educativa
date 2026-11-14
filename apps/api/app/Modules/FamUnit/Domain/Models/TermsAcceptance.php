<?php

namespace App\Modules\FamUnit\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\AppendOnlyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-FAM-UNIT/datos.md §1.9, §4 (1.14-c, `REQ-FAM-UNIT-003`, `RN-FAM-UNIT-64`). La aceptación de una versión de las
 * condiciones de uso por un usuario: inmutable (`AppendOnlyModel` y `REVOKE UPDATE, DELETE` en el motor); es la prueba
 * de lo que el tutor leyó (`shown_locale`).
 *
 * ADR-035: `Selective` (`datos.md §4` decía `Full`; decisión de implementación `funcional.md §19` D-29, mismo criterio
 * que `ImageConsentDecision`, D-17): `Full` registraría la IP y el agente de usuario en `audit_logs.changes`, y nada
 * identificativo va ahí. Se registra el valor de la versión, el idioma mostrado y el instante; `user_id`, `ip_address` y
 * `user_agent` se redactan como `identifier` (el autor ya consta en la entrada de auditoría). Escrituras por instancia (AR-15). Los demás módulos no usan este modelo (AR-01).
 *
 * @mixin IdeHelperTermsAcceptance
 */
class TermsAcceptance extends AppendOnlyModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'terms_acceptances';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = ['terms_version_id', 'shown_locale', 'accepted_at'];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /** Nada es asignable en masa: la aceptación se rellena por instancia desde el servicio (AR-15). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<TermsVersion, $this>
     */
    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(TermsVersion::class, 'terms_version_id');
    }
}
