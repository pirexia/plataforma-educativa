<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\DestinationMasker;
use App\Modules\Auth\Domain\Events\IdentityUnlinked;
use App\Modules\Auth\Domain\Models\UserIdentity;
use App\Modules\Auth\Infrastructure\Jobs\SendIdentityUnlinkedEmail;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

/**
 * `funcional.md §E.4.5`, `api.md §E.5`, `DELETE /auth/identities/{public_id}`.
 * Mismo criterio que `MfaFactorRemovalService`: retirar una vía de acceso
 * exige demostrar que sigues siendo tú (`RN-AUTH-60`).
 */
final class IdentityUnlinkingService
{
    public function __construct(
        private readonly LoginAttemptRecorder $attempts,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @throws ApiException validation() (422, contraseña), notFound() (404),
     *                      conflict() (409, `RN-AUTH-96`)
     */
    public function unlink(User $user, string $identityPublicId, string $currentPassword): void
    {
        // funcional.md §E.4.5 punto 3, RN-AUTH-96: comprobada ANTES que la
        // contraseña, a propósito — mismo criterio con el que RN-AUTH-16
        // comprueba el bloqueo antes que la credencial. Hoy inalcanzable
        // (`users.password` es NOT NULL, RN-AUTH-20, y 1.4 no crea cuentas
        // sin ella, RN-AUTH-99), pero si se comprobara DESPUÉS del
        // `Hash::check()` sería además inalcanzable en el sentido malo:
        // un usuario sin contraseña utilizable jamás podría aportar una
        // "contraseña actual" correcta, así que el 409 nunca se llegaría
        // a producir con ese orden — el `422` de contraseña incorrecta lo
        // taparía siempre. Escrita contra el único hueco que el tipo
        // `string` de `User::$password` deja abierto —una cadena
        // vacía—, para 1.4b (*just-in-time provisioning*, OPEN-AUTH-31),
        // con su propio test (CA-AUTH-227) que construye el estado a mano.
        if ($user->password === '') {
            throw ApiException::conflict('auth.validation.identity_would_leave_user_without_access');
        }

        // funcional.md §E.4.5 punto 2: 422, no 401 — la sesión sigue
        // siendo válida. El fallo cuenta hacia el bloqueo de
        // (tenant_id, email), igual que `PasswordChangeService` y
        // `MfaFactorRemovalService`.
        if (! Hash::check($currentPassword, $user->password)) {
            $this->attempts->recordFailure($user->email, $user);

            $errors = new ValidationErrorBag;
            $errors->add('current_password', 'auth.validation.current_password_incorrect', 'auth.validation.current_password_incorrect');
            $errors->throwIfAny();
        }

        // permisos.md §E.4: public_id + user_id del portador en el mismo
        // WHERE (RN-AUTH-41) — el tenant lo acota el scope de TenantModel.
        $identity = UserIdentity::query()
            ->where('user_id', $user->id)
            ->where('public_id', $identityPublicId)
            ->first();

        if ($identity === null) {
            throw ApiException::notFound();
        }

        $emailAtLink = $identity->email_at_link ?? '';
        $provider = $identity->provider;

        // Borrado lógico (INV-004): SoftDeletes de TenantModel deja
        // deleted_at, el observer audita el `deleted` — sin código propio
        // (RN-AUTH-74, funcional.md §E.8).
        $identity->delete();

        event(new IdentityUnlinked($this->tenantContext->tenantId(), $user->public_id, $provider));

        $tenant = Tenant::query()->find($this->tenantContext->tenantId());

        SendIdentityUnlinkedEmail::dispatch(
            recipientEmail: $user->email,
            recipientGivenName: $user->person->given_name ?? '',
            recipientLocale: $user->person->locale ?? 'es-ES',
            tenantName: $tenant->name ?? '',
            unlinkedEmailMasked: DestinationMasker::maskEmail($emailAtLink),
        );
    }
}
