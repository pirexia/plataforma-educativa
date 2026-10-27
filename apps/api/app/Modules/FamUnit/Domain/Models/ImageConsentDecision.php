<?php

namespace App\Modules\FamUnit\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\AppendOnlyModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-FAM-UNIT/datos.md §1.6, §4 (1.14-b, `REQ-FAM-UNIT-004`, `ADR-066 §5.8`, `RN-FAM-UNIT-42`). Una decisión de imagen
 * de un tutor sobre un alumno y un nivel: inmutable (`AppendOnlyModel` y `REVOKE UPDATE, DELETE` en el motor), corregir
 * es registrar otra. **No hay modelo de estado vigente** (`ADR-066 §5.2`): el estado se calcula en cada consulta.
 *
 * ADR-035: `Selective`. Se registra el valor de los códigos y marcas (nivel, decisión, canal, versión del texto, idioma
 * mostrado, instante); las claves foráneas a alumno, tutor y autor, la IP y el agente de usuario son identificativos y
 * se redactan como `identifier` por no estar en la lista (`datos.md §4`: decisión de implementación). Escrituras por
 * instancia (AR-15). Los demás módulos no usan este modelo (AR-01): consultan `ImageConsentPolicy`.
 *
 * @mixin IdeHelperImageConsentDecision
 */
class ImageConsentDecision extends AppendOnlyModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    public const LEVELS = ['web', 'redes_sociales'];

    public const DECISIONS = ['autorizo', 'no_autorizo'];

    public const CHANNELS = ['portal', 'presencial'];

    protected $table = 'image_consent_decisions';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = ['level', 'decision', 'channel', 'consent_text_version_id', 'shown_locale', 'occurred_at'];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    /** Nada es asignable en masa: la decisión se rellena por instancia desde el servicio (AR-15). */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<TermsVersion, $this>
     */
    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(TermsVersion::class, 'consent_text_version_id');
    }

    protected static function booted(): void
    {
        parent::booted();

        // `AppendOnlyModel` no gestiona marcas de tiempo; el autor lo fija el servicio (`recorded_by`) y, si no, el
        // usuario autenticado.
        static::creating(function (self $model): void {
            $model->recorded_by ??= AuditActor::resolveUserId();
        });
    }
}
