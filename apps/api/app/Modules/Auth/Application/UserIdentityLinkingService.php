<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\DestinationMasker;
use App\Modules\Auth\Domain\Events\IdentityLinked;
use App\Modules\Auth\Domain\LinkMethod;
use App\Modules\Auth\Domain\Models\IdentityProvider;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Modules\Auth\Infrastructure\Jobs\SendIdentityLinkedEmail;
use App\Modules\Auth\Infrastructure\Jobs\SendIdentityMatchedEmail;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * funcional.md §E.4.3, §E.4.4. El único punto que escribe una fila de
 * `user_identities` por fusión o por vinculación desde el perfil —
 * `RN-AUTH-88`: solo esa fila, nada más. Compartido por
 * `GoogleOAuthCallbackService`/`OidcCallbackService`/`SamlAcsService`
 * (fusión y vinculación sin segundo factor de por medio) y por
 * `MfaChallengeService::verify()` (fusión que esperó a que el segundo
 * factor se superase).
 *
 * `linkViaSso()` (1.4b, `funcional.md §F.4.3.1`, ampliado en `§G.4.3` para
 * SAML): el equivalente institucional, con `link_method =
 * 'emparejamiento_sso'`, `provider` derivado del `protocol` del proveedor
 * catalogado (`'oidc'` o `'saml'`) e `identity_provider_id` informado —
 * nunca `link()`, que sigue siendo exclusivo de
 * `fusion_automatica`/`perfil` sobre el *driver* global de Google
 * (`RN-AUTH-106`: el `CHECK` de 1.4 no se toca ni se reutiliza)—, y un
 * aviso distinto (`SendIdentityMatchedEmail`).
 */
final class UserIdentityLinkingService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * `$identityProvider` (1.4b, ampliado a SAML en 1.4c): vinculación
     * manual desde el perfil (`intent = link`, `api.md §F.6`/`§G.6`) con
     * un proveedor catalogado — `provider` derivado de su `protocol` en
     * vez de `'google'`. Fuera de ese caso, sin cambios respecto de 1.4.
     */
    public function link(
        User $user,
        string $subject,
        string $email,
        bool $emailVerified,
        LinkMethod $linkMethod,
        ?IdentityProvider $identityProvider = null,
    ): UserIdentity {
        $provider = $identityProvider?->protocol->value ?? 'google';

        $identity = UserIdentity::create([
            'user_id' => $user->id,
            'identity_provider_id' => $identityProvider?->id,
            'provider' => $provider,
            'subject' => $subject,
            'email_at_link' => $email,
            'email_verified_at_link' => $emailVerified,
            'link_method' => $linkMethod,
            'linked_at' => now(),
        ]);

        event(new IdentityLinked(
            $this->tenantContext->tenantId(),
            $user->public_id,
            $provider,
            $linkMethod->value,
        ));

        $tenant = Tenant::query()->find($this->tenantContext->tenantId());

        SendIdentityLinkedEmail::dispatch(
            recipientEmail: $user->email,
            recipientGivenName: $user->person->given_name ?? '',
            recipientLocale: $user->person->locale ?? 'es-ES',
            tenantName: $tenant->name ?? '',
            linkedEmailMasked: DestinationMasker::maskEmail($email),
            linkMethod: $linkMethod->value,
        );

        return $identity;
    }

    /**
     * `RN-AUTH-109`: escribe la fila de `user_identities` y nada más —
     * ni contraseña, ni estado, ni correo, ni persona, ni roles, ni
     * idioma, ni un solo ajuste. `email_verified_at_link` es telemetría
     * de lo que dijo el emisor (`RN-AUTH-106`), no la base de la
     * decisión: la confianza viene de que el centro catalogó ese emisor.
     */
    public function linkViaSso(User $user, IdentityProvider $provider, string $subject, string $email, bool $emailVerified): UserIdentity
    {
        $protocolValue = $provider->protocol->value;

        $identity = UserIdentity::create([
            'user_id' => $user->id,
            'identity_provider_id' => $provider->id,
            'provider' => $protocolValue,
            'subject' => $subject,
            'email_at_link' => $email,
            'email_verified_at_link' => $emailVerified,
            'link_method' => LinkMethod::EmparejamientoSso,
            'linked_at' => now(),
        ]);

        event(new IdentityLinked(
            $this->tenantContext->tenantId(),
            $user->public_id,
            $protocolValue,
            LinkMethod::EmparejamientoSso->value,
        ));

        $tenant = Tenant::query()->find($this->tenantContext->tenantId());

        SendIdentityMatchedEmail::dispatch(
            recipientEmail: $user->email,
            recipientGivenName: $user->person->given_name ?? '',
            recipientLocale: $user->person->locale ?? 'es-ES',
            tenantName: $tenant->name ?? '',
            providerDisplayName: $provider->display_name,
            matchedEmailMasked: DestinationMasker::maskEmail($email),
        );

        return $identity;
    }
}
