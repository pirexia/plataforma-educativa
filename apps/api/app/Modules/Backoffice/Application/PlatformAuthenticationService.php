<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Auth\Domain\MfaVerifier;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaChallenge;
use App\Modules\Backoffice\Domain\Models\PlatformAdminMfaFactor;
use App\Modules\Backoffice\Domain\Models\PlatformAdminSession;
use App\Modules\Backoffice\Domain\PlatformAdminSessionEndReason;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Http\Middleware\RequirePlatformReauthentication;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * REQ-BO-007, funcional.md §5.1, §5.2. Credenciales, segundo factor
 * obligatorio y reautenticación para operaciones sensibles.
 *
 * `RN-BO-05`: sin período de gracia y sin exención — `platform_admin_mfa_
 * factors` no tiene equivalente de `user_mfa_obligations`/
 * `user_mfa_exemptions`.
 *
 * Límite de tasa y bloqueo por intentos (funcional.md §5.1 punto 3):
 * `datos.md` no define una tabla de bloqueo propia para `platform_admins`
 * (a diferencia de `account_lockouts` en `REQ-AUTH-001`). Se implementa
 * con `RateLimiter` (contador propio, por correo normalizado + IP, sin
 * tabla nueva) en vez de una tabla — señalado explícitamente para que
 * `db-reviewer` confirme si el requisito exige más que esto.
 */
final class PlatformAuthenticationService
{
    private const DECOY_HASH = '$2y$12$k13rBy62WMZL2PB2dZz4zuPnzkLke4Pz1XRGyE21EtYNdYaV7Qhru';

    private const MAX_ATTEMPTS = 10;

    private const DECAY_SECONDS = 900;

    public function __construct(
        private readonly AdminActionLogRecorder $recorder,
        private readonly MfaVerifier $mfaVerifier,
    ) {}

    /**
     * POST /auth/session. Devuelve el admin si las credenciales son
     * válidas — la sesión completa NO se emite aquí: si tiene MFA
     * confirmado, el controlador abre un desafío; si no, RN-BO-05 lo
     * limita a las rutas de alta.
     */
    public function attempt(string $email, string $password, string $ip): PlatformAdmin
    {
        $key = "platform-login:{$ip}:".Str::lower($email);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ApiException::tooManyRequests(RateLimiter::availableIn($key));
        }

        $admin = PlatformAdmin::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->first();

        $hash = $admin === null ? self::DECOY_HASH : $admin->password;

        if (! Hash::check($password, $hash) || $admin === null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $this->recorder->record(action: AdminActionLogAction::AccesoRechazado, context: ['motivo' => 'credenciales']);

            throw ApiException::unauthenticated();
        }

        if ($admin->status !== PlatformAdminStatus::Activo) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $this->recorder->record(action: AdminActionLogAction::AccesoRechazado, subjectPublicId: $admin->public_id, context: ['motivo' => 'suspendido']);

            throw ApiException::unauthenticated();
        }

        RateLimiter::clear($key);

        return $admin;
    }

    /**
     * Emite el desafío TOTP para un administrador con factor confirmado.
     * `challenge_hash` es el hash del identificador de la sesión HTTP en
     * curso: es lo que ata el desafío a ESTA sesión, sin exponer el
     * identificador en claro en la tabla (mismo papel que `session_id`
     * en `mfa_challenges` de tenant, aquí hasheado).
     */
    public function issueChallenge(PlatformAdmin $admin, string $sessionId): PlatformAdminMfaChallenge
    {
        PlatformAdminMfaChallenge::query()
            ->where('platform_admin_id', $admin->id)
            ->whereNull('consumed_at')
            ->delete();

        return PlatformAdminMfaChallenge::create([
            'platform_admin_id' => $admin->id,
            'challenge_hash' => hash('sha256', $sessionId),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
        ]);
    }

    /**
     * POST /auth/session/mfa. Resuelve el desafío y deja la sesión lista
     * para que el controlador la regenere y registre el acceso.
     */
    public function resolveChallenge(string $sessionId, string $code): PlatformAdmin
    {
        $challenge = PlatformAdminMfaChallenge::query()
            ->where('challenge_hash', hash('sha256', $sessionId))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($challenge === null) {
            throw ApiException::unauthenticated();
        }

        if ($challenge->attempts >= 5) {
            throw ApiException::unauthenticated();
        }

        $admin = $challenge->admin;
        $factor = PlatformAdminMfaFactor::query()
            ->where('platform_admin_id', $admin->id)
            ->whereNotNull('confirmed_at')
            ->first();

        $verifiedStep = $factor !== null
            ? $this->mfaVerifier->verify($factor->secret_encrypted, $code, $factor->last_used_step)
            : null;

        if ($verifiedStep === null) {
            $challenge->increment('attempts');
            $this->recorder->record(action: AdminActionLogAction::AccesoRechazado, subjectPublicId: $admin->public_id, context: ['motivo' => 'mfa']);

            throw ApiException::unauthenticated();
        }

        $factor->forceFill(['last_used_step' => $verifiedStep, 'last_used_at' => now()])->save();
        $challenge->forceFill(['consumed_at' => now()])->save();

        return $admin;
    }

    /**
     * Fila de negocio de la sesión (datos.md §2.7). Se escribe DENTRO de
     * la transacción de la petición, con el identificador que
     * `session()->getId()` ya conoce tras `regenerate()` — antes de que
     * el driver escriba `platform_sessions` al final de la petición
     * (ADR-047 §5.1, §2.7.1: por eso `session_id` no lleva FK).
     */
    public function registerSession(PlatformAdmin $admin, string $sessionId, ?string $ip, ?string $userAgent): PlatformAdminSession
    {
        $admin->forceFill(['last_login_at' => now()])->save();

        $session = PlatformAdminSession::create([
            'platform_admin_id' => $admin->id,
            'session_id' => $sessionId,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->recorder->record(action: AdminActionLogAction::AccesoConcedido, subjectPublicId: $admin->public_id);

        return $session;
    }

    public function reauthenticate(PlatformAdmin $admin, string $password, string $code): void
    {
        if (! Hash::check($password, $admin->password)) {
            throw ApiException::unauthenticated();
        }

        $factor = PlatformAdminMfaFactor::query()
            ->where('platform_admin_id', $admin->id)
            ->whereNotNull('confirmed_at')
            ->first();

        $verifiedStep = $factor !== null
            ? $this->mfaVerifier->verify($factor->secret_encrypted, $code, $factor->last_used_step)
            : null;

        if ($verifiedStep === null) {
            throw ApiException::unauthenticated();
        }

        $factor->forceFill(['last_used_step' => $verifiedStep, 'last_used_at' => now()])->save();

        request()->session()->put(RequirePlatformReauthentication::SESSION_KEY, now());

        PlatformAdminSession::query()
            ->where('session_id', request()->session()->getId())
            ->update(['reauthenticated_at' => now()]);

        $this->recorder->record(action: AdminActionLogAction::ReautenticacionSuperada, subjectPublicId: $admin->public_id);
    }

    public function endSession(PlatformAdmin $admin, string $sessionId): void
    {
        PlatformAdminSession::query()
            ->where('platform_admin_id', $admin->id)
            ->where('session_id', $sessionId)
            ->whereNull('ended_at')
            ->get()
            ->each(fn (PlatformAdminSession $session) => $session->close(PlatformAdminSessionEndReason::CierreUsuario));

        $this->recorder->record(action: AdminActionLogAction::SesionCerrada, subjectPublicId: $admin->public_id);
    }
}
