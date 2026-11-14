<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Domain\DestinationMasker;
use App\Modules\Auth\Domain\MfaObligationState;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\MfaRecoveryCode;
use App\Modules\Auth\Domain\Models\UserMfaExemption;
use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Api\ApiException;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §C.1, §D.3.1, `GET /auth/mfa`. Autoservicio: estado completo del
 * propio usuario (factores, códigos de respaldo restantes, obligación) —
 * el bloque `mfa` de `GET /me` es un resumen para cada acceso (`§C.4.8`),
 * este endpoint es la pantalla de `/cuenta/seguridad` (`§C.11`).
 *
 * 1.3b (`§D.2.4`, `§D.3.1`): tres campos aditivos — `allowed_methods` (lo
 * que el tenant admite, para que la pantalla ofrezca el correo solo si
 * procede), `destination_masked` por factor de entrega, y
 * `mfa.exempt_until` (la caducidad de la excepción propia, sin enviar
 * ningún correo — `funcional.md §D.4.10`).
 */
class MfaStatusController extends Controller
{
    public function __construct(
        private readonly MfaPolicy $policy,
        private readonly TenantSettingsReader $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        // RN-AUTH-59: un factor no confirmado no aparece como factor.
        $factors = MfaFactor::query()
            ->where('user_id', $user->id)
            ->whereNotNull('confirmed_at')
            ->orderByDesc('is_preferred')
            ->get();

        $unusedRecoveryCodes = MfaRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->count();

        $obligation = $this->policy->resolve($user);

        $daysRemaining = $obligation->state === MfaObligationState::EnGracia && $obligation->graceDeadlineAt !== null
            ? max(0, (int) now()->diffInDays($obligation->graceDeadlineAt))
            : null;

        $liveExemption = UserMfaExemption::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        return [
            'allowed_methods' => $this->settings->mfaAllowedMethods(),
            'factors' => $factors->map(function (MfaFactor $factor) use ($user): array {
                $presented = [
                    'public_id' => $factor->public_id,
                    'method' => $factor->method->value,
                    'is_preferred' => $factor->is_preferred,
                ];

                if ($factor->method->requiresDelivery()) {
                    $presented['destination_masked'] = DestinationMasker::maskEmail($user->email);
                }

                $presented['confirmed_at'] = $factor->confirmed_at->toISOString();
                $presented['last_used_at'] = $factor->last_used_at?->toISOString();

                return $presented;
            })->all(),
            'unused_recovery_codes_count' => $unusedRecoveryCodes,
            'mfa' => [
                'enrolled' => $this->policy->hasUsableFactor($user),
                'obligated' => $obligation->isObligated(),
                'enforced' => $obligation->isEnforced(),
                'grace_deadline_at' => $obligation->graceDeadlineAt?->toISOString(),
                'days_remaining' => $daysRemaining,
                'exempt_until' => $liveExemption?->expires_at->toISOString(),
            ],
        ];
    }
}
