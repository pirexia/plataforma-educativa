<?php

namespace App\Modules\Auth\Infrastructure;

use App\Models\User;
use App\Modules\Auth\Domain\PasswordResetToken;
use App\Modules\Auth\Domain\PasswordResetTokenRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * funcional.md §7.2, datos.md §A.3, issue #18. Consulta por *query
 * builder*, no por Eloquent: `password_reset_tokens` tiene clave primaria
 * compuesta (tenant_id, email), que Eloquent no modela. Conexión fijada a
 * `pgsql` (rol `plataforma_app`, sujeto a RLS) — nunca `pgsql_platform`
 * (BYPASSRLS). El predicado de tenant es explícito en cada consulta,
 * además de la RLS (RN-AUTH-07): `tenantId()` lanza si no hay contexto
 * activo, así que un uso sin tenant revienta en vez de leer la tabla
 * entera.
 */
final class DatabasePasswordResetTokenRepository implements PasswordResetTokenRepository
{
    private const CONNECTION = 'pgsql';

    private const TABLE = 'password_reset_tokens';

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function issueFor(User $user): string
    {
        $tenantId = $this->tenantContext->tenantId();
        $rawToken = bin2hex(random_bytes(32));
        $ttlMinutes = (int) config('auth-local.password_reset_ttl_minutes');

        // RN-AUTH-11: sustituye cualquier token vivo del mismo correo — la
        // clave primaria (tenant_id, email) lo garantiza por construcción,
        // sin comprobación de aplicación previa.
        DB::connection(self::CONNECTION)->table(self::TABLE)->updateOrInsert(
            ['tenant_id' => $tenantId, 'email' => $user->email],
            [
                'token_hash' => hash('sha256', $rawToken),
                'token' => null,
                'expires_at' => now()->addMinutes($ttlMinutes),
                'created_at' => now(),
            ],
        );

        return $rawToken;
    }

    public function findValid(string $token): ?PasswordResetToken
    {
        $tenantId = $this->tenantContext->tenantId();

        $row = DB::connection(self::CONNECTION)->table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($row === null || $row->expires_at === null || now()->greaterThanOrEqualTo($row->expires_at)) {
            return null;
        }

        return new PasswordResetToken(email: $row->email, expiresAt: Carbon::parse($row->expires_at));
    }

    public function consume(PasswordResetToken $token): void
    {
        $tenantId = $this->tenantContext->tenantId();

        DB::connection(self::CONNECTION)->table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('email', $token->email)
            ->delete();
    }

    /**
     * findActiveByEmail() de purga (PurgeExpiredPasswordResetTokens) no
     * necesita tenant activo por fila: se ejecuta por tenant vía
     * RunsPerTenant, así que reutiliza el contexto igual que las demás.
     */
    public function deleteExpired(): int
    {
        $tenantId = $this->tenantContext->tenantId();

        return DB::connection(self::CONNECTION)->table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('expires_at', '<', now())
            ->delete();
    }

    /**
     * §8.2: `UserEmailChanged` invalida el token vivo del correo anterior.
     */
    public function deleteForEmail(string $email): void
    {
        $tenantId = $this->tenantContext->tenantId();

        DB::connection(self::CONNECTION)->table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->delete();
    }
}
