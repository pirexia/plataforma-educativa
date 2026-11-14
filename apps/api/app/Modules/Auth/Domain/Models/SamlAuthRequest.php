<?php

namespace App\Modules\Auth\Domain\Models;

use App\Models\User;
use App\Support\Tenancy\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * datos.md §G.4.1 (REQ-AUTH-004, 1.4c). La correlación del `AuthnRequest`
 * emitido: sostiene la excepción de CSRF del ACS (`RN-AUTH-120`,
 * `RN-AUTH-124`). Sin `HasPublicId`: no se expone en ninguna URL ni
 * respuesta de API — el identificador que viaja es `request_id`, dentro
 * del propio mensaje SAML.
 *
 * **No implementa `Auditable`**: política de auditoría `None`
 * (`datos.md §G.4.1`). Es estado transitorio de protocolo con vida de
 * cinco minutos, del mismo carácter que el `state` de OIDC en sesión, que
 * tampoco se audita.
 *
 * @mixin IdeHelperSamlAuthRequest
 */
class SamlAuthRequest extends TenantModel
{
    protected $table = 'saml_auth_requests';

    protected $fillable = [
        'identity_provider_id',
        'request_id',
        'intent',
        'linking_user_id',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<IdentityProvider, $this>
     */
    public function identityProvider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class);
    }

    /**
     * `null` cuando `intent = 'login'` (`CHECK saml_auth_requests_login_no_user_check`).
     *
     * @return BelongsTo<User, $this>
     */
    public function linkingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linking_user_id');
    }
}
