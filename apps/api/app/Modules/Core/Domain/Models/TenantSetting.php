<?php

namespace App\Modules\Core\Domain\Models;

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Audit\HasAuditableAttributes;
use App\Support\Audit\RecordsAuditTrail;
use App\Support\Database\HasPublicId;
use App\Support\Tenancy\TenantModel;

/**
 * datos.md §A.1. Selective: se redactan como `identifier` los datos
 * fiscales (razón social, NIF, dirección) porque en un centro que sea
 * persona física o sociedad unipersonal son datos personales; las claves
 * de objeto de branding quedan fuera por no aportar nada legible.
 *
 * @mixin IdeHelperTenantSetting
 */
class TenantSetting extends TenantModel implements Auditable
{
    use HasAuditableAttributes;
    use HasPublicId;
    use RecordsAuditTrail;

    protected $table = 'tenant_settings';

    /** @var array<int, string> */
    protected array $auditRecordedAttributes = [
        'default_locale', 'active_locales', 'timezone', 'currency', 'autonomous_community',
        'color_primary', 'color_secondary', 'session_timeout_minutes',
        // REQ-AUTH/datos.md §C.7.2 (1.3): configuración del tenant, no
        // dato personal.
        'mfa_allowed_methods', 'mfa_grace_period_days',
        'deleted_at', 'created_by', 'updated_by',
    ];

    /** @var array<int, string> */
    protected array $auditSecretAttributes = [];

    public function auditValuePolicy(): AuditValuePolicy
    {
        return AuditValuePolicy::Selective;
    }

    protected $fillable = [
        'default_locale',
        'active_locales',
        'timezone',
        'currency',
        'autonomous_community',
        'legal_name',
        'tax_id',
        'fiscal_address',
        'fiscal_postal_code',
        'fiscal_city',
        'fiscal_province',
        'fiscal_country_code',
        'color_primary',
        'color_secondary',
        'logo_object_key',
        'favicon_object_key',
        'login_background_object_key',
        // REQ-AUTH/datos.md §A.4 (REQ-AUTH-005 punto 1).
        'session_timeout_minutes',
        // REQ-AUTH/datos.md §C.7.2 (REQ-AUTH-003, 1.3).
        'mfa_allowed_methods',
        'mfa_grace_period_days',
    ];

    protected $casts = [
        'active_locales' => 'array',
        'mfa_allowed_methods' => 'array',
    ];
}
