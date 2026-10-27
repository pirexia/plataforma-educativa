<?php

namespace App\Modules\FamUnit\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * REQ-FAM-UNIT/datos.md §1.8, §4 (1.14-b, `REQ-FAM-UNIT-003`/`-004`, `ADR-066 §5.5`). Versión de un texto legal del
 * centro con su `kind` (`condiciones_uso`, `consentimiento_imagen_web`, `consentimiento_imagen_redes`). Título y cuerpo
 * son contenido del centro traducible (`ADR-062`).
 *
 * ADR-035: `Full`. Son textos del centro, sin datos personales; los largos se redactan solos como `oversized`
 * (`ADR-035 §4`) y el desglose por idioma es el de `ADR-062 §5.5`. Escrituras por instancia, nunca masivas (AR-15):
 * `kind`, estado y fechas de publicación no son asignables en masa. Los demás módulos no usan este modelo (AR-01).
 *
 * @mixin IdeHelperTermsVersion
 */
class TermsVersion extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    public const KIND_TERMS_OF_USE = 'condiciones_uso';

    public const KIND_IMAGE_WEB = 'consentimiento_imagen_web';

    public const KIND_IMAGE_SOCIAL = 'consentimiento_imagen_redes';

    public const KINDS = [self::KIND_TERMS_OF_USE, self::KIND_IMAGE_WEB, self::KIND_IMAGE_SOCIAL];

    public const STATUS_DRAFT = 'borrador';

    public const STATUS_PUBLISHED = 'publicada';

    public const STATUS_RETIRED = 'retirada';

    protected $table = 'terms_versions';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Full;
    }

    /** @var list<string> */
    protected $fillable = ['title_translations', 'body_translations'];

    protected function casts(): array
    {
        return [
            'title_translations' => 'array',
            'body_translations' => 'array',
            'version_number' => 'integer',
            'requires_reacceptance' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
