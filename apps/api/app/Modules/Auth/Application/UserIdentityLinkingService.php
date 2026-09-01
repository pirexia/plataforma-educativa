<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\DestinationMasker;
use App\Modules\Auth\Domain\Events\IdentityLinked;
use App\Modules\Auth\Domain\LinkMethod;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Modules\Auth\Infrastructure\Jobs\SendIdentityLinkedEmail;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * funcional.md §E.4.3, §E.4.4. El único punto que escribe una fila de
 * `user_identities` por fusión o por vinculación desde el perfil —
 * `RN-AUTH-88`: solo esa fila, nada más. Compartido por
 * `GoogleOAuthCallbackService` (fusión y vinculación sin segundo factor
 * de por medio) y por `MfaChallengeService::verify()` (fusión que
 * esperó a que el segundo factor se superase).
 */
final class UserIdentityLinkingService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function link(User $user, string $subject, string $email, bool $emailVerified, LinkMethod $linkMethod): UserIdentity
    {
        $identity = UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'subject' => $subject,
            'email_at_link' => $email,
            'email_verified_at_link' => $emailVerified,
            'link_method' => $linkMethod,
            'linked_at' => now(),
        ]);

        event(new IdentityLinked(
            $this->tenantContext->tenantId(),
            $user->public_id,
            'google',
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
}
