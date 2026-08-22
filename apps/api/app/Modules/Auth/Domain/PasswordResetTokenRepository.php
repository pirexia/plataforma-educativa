<?php

namespace App\Modules\Auth\Domain;

use App\Models\User;

/**
 * funcional.md §7.2, issue #18. Repositorio propio, consciente de tenant
 * en cada consulta (`RN-AUTH-07`, predicado explícito además de RLS) —
 * nunca `Illuminate\Support\Facades\Password`, `PasswordBroker` ni
 * `DatabaseTokenRepository` de Laravel (prohibido por test de arquitectura,
 * `CA-AUTH-034`).
 */
interface PasswordResetTokenRepository
{
    /**
     * Genera un token de 32 bytes, persiste solo su hash SHA-256 y
     * sustituye cualquier token vivo anterior del mismo correo
     * (RN-AUTH-11: como mucho uno vivo por tenant y correo). Devuelve el
     * token en claro — nunca se persiste.
     */
    public function issueFor(User $user): string;

    /**
     * Busca por (tenant_id, sha256($token)), con el tenant del contexto
     * activo. `null` si no existe, si caducó, o pertenece a otro tenant.
     */
    public function findValid(string $token): ?PasswordResetToken;

    /**
     * Borra la fila (un solo uso garantizado por la desaparición de la
     * fila, RN-AUTH-12).
     */
    public function consume(PasswordResetToken $token): void;

    /**
     * operacion.md §4.1: `PurgeExpiredPasswordResetTokens`. Devuelve el
     * número de filas borradas.
     */
    public function deleteExpired(): int;

    /**
     * §8.2: evento `UserEmailChanged` — invalida el token vivo del correo
     * anterior.
     */
    public function deleteForEmail(string $email): void;
}
