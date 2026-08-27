<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Modules\Auth\Domain\Events\MfaFactorRemoved;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\MfaRecoveryCode;
use App\Modules\Auth\Infrastructure\Jobs\SendMfaFactorRemovedEmail;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Api\ApiException;
use App\Support\Api\ValidationErrorBag;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * funcional.md §C.4.6. Desactivación de un factor por el propio usuario.
 */
final class MfaFactorRemovalService
{
    public function __construct(
        private readonly MfaPolicy $policy,
        private readonly TenantSettingsReader $settings,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @throws ApiException validation() (422, contraseña), notFound() (404),
     *                      conflict() (409, `RN-AUTH-61`)
     */
    public function remove(User $user, string $factorPublicId, string $currentPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            $errors = new ValidationErrorBag;
            $errors->add('current_password', 'auth.validation.current_password_incorrect', 'auth.validation.current_password_incorrect');
            $errors->throwIfAny();
        }

        $factor = MfaFactor::query()
            ->where('user_id', $user->id)
            ->where('public_id', $factorPublicId)
            ->whereNotNull('confirmed_at')
            ->first();

        if ($factor === null) {
            throw ApiException::notFound();
        }

        $allowedMethods = $this->settings->mfaAllowedMethods();

        $hasOtherUsableFactor = MfaFactor::query()
            ->where('user_id', $user->id)
            ->whereNotNull('confirmed_at')
            ->where('id', '!=', $factor->id)
            ->whereIn('method', $allowedMethods)
            ->exists();

        // RN-AUTH-61: solo importa si ESTE es el último factor utilizable.
        if (! $hasOtherUsableFactor
            && $this->policy->requiredByRoleCodes($user) !== []
            && ! $this->policy->hasLiveExemption($user)
        ) {
            throw ApiException::conflict('auth.validation.mfa_factor_required_by_role');
        }

        DB::transaction(function () use ($factor, $user, $hasOtherUsableFactor): void {
            $factor->delete();

            // §C.4.6 punto 3: si era su último factor confirmado, los
            // códigos de respaldo tampoco protegen nada — se borran.
            if (! $hasOtherUsableFactor) {
                MfaRecoveryCode::query()->where('user_id', $user->id)->delete();
            }
        });

        event(new MfaFactorRemoved(
            $this->tenantContext->tenantId(),
            $user->public_id,
            $factor->method->value,
            removedByAdmin: false,
        ));

        SendMfaFactorRemovedEmail::dispatch(
            recipientEmail: $user->email,
            recipientGivenName: $user->person->given_name ?? '',
            recipientLocale: $user->person->locale ?? 'es-ES',
            tenantName: Tenant::query()->find($this->tenantContext->tenantId())->name ?? '',
            byAdmin: false,
        );
    }
}
