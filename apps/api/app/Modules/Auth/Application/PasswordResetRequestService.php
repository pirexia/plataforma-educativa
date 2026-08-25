<?php

namespace App\Modules\Auth\Application;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Auth\Domain\PasswordResetTokenRepository;
use App\Modules\Auth\Infrastructure\Jobs\SendPasswordResetEmail;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * funcional.md §4.5 fase 1. RN-AUTH-10: sin efecto observable distinto
 * exista o no la cuenta — el controlador responde `202` siempre, este
 * servicio simplemente no hace nada cuando no hay usuario `activo` con
 * ese correo (§4.5 punto 4: `pendiente` e `inactivo` tampoco reciben
 * correo).
 */
final class PasswordResetRequestService
{
    public function __construct(
        private readonly PasswordResetTokenRepository $resetTokens,
        private readonly AuditRecorder $auditRecorder,
        private readonly TenantContext $tenantContext,
    ) {}

    public function request(string $email): void
    {
        $normalizedEmail = Str::lower(trim($email));

        $user = User::query()
            ->where('email', $normalizedEmail)
            ->where('status', UserStatus::Activo)
            ->first();

        if (! $user instanceof User) {
            return;
        }

        $rawToken = $this->resetTokens->issueFor($user);
        $expiresInMinutes = (int) config('auth-local.password_reset_ttl_minutes');

        $tenant = Tenant::query()->find($this->tenantContext->tenantId());
        $locale = $user->person->locale ?? 'es-ES';

        SendPasswordResetEmail::dispatch(
            rawToken: $rawToken,
            recipientEmail: $user->email,
            recipientGivenName: $user->person->given_name ?? '',
            recipientLocale: $locale,
            tenantName: $tenant->name ?? '',
            tenantSlug: $tenant->slug ?? '',
            expiresInMinutes: $expiresInMinutes,
        );

        $this->auditRecorder->record($user, 'password_reset_requested');
    }
}
