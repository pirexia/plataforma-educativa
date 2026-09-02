<?php

namespace App\Modules\Auth\Domain\Models;

use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §G.4.2 (REQ-AUTH-004, 1.4c). Cubre un ataque distinto de
 * `SamlAuthRequest`: que una misma aserción legítima se reenvíe contra
 * OTRA petición viva (`RN-AUTH-122`). No guarda el XML de la aserción ni
 * ningún fragmento suyo (`CA-AUTH-363`).
 *
 * **No implementa `Auditable`**: política de auditoría `None`, mismo
 * argumento que `SamlAuthRequest`.
 *
 * @mixin IdeHelperSamlConsumedAssertion
 */
class SamlConsumedAssertion extends TenantModel
{
    protected $table = 'saml_consumed_assertions';

    protected $fillable = [
        'identity_provider_id',
        'assertion_id',
        'not_on_or_after',
    ];

    protected $casts = [
        'not_on_or_after' => 'datetime',
    ];

    /**
     * @return BelongsTo<IdentityProvider, $this>
     */
    public function identityProvider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class);
    }
}
