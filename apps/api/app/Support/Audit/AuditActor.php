<?php

namespace App\Support\Audit;

use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * ADR-035 §8 (auditable_type de ADR-034 §3): resuelve actor_user_id y
 * actor_type. override() da a REQ-ONB (importación) y a
 * TenantContext::runAsPlatform() un sitio donde declarar
 * 'import'/'platform' explícitamente cuando existan, en vez de que este
 * código intente adivinarlo.
 *
 * ADR-039 §7 (OPEN-AUTH-12): rama 'anonymous' para una petición HTTP real
 * sin sesión establecida y fuera de consola — el caso de
 * `password_reset_requested`, que no tiene actor identificado y no es "el
 * sistema" (eso queda reservado a jobs y comandos programados de verdad).
 * Va antes de la rama 'system' por descarte, precisamente para que ese
 * descarte deje de aplicar a las peticiones anónimas.
 */
final class AuditActor
{
    private static ?string $override = null;

    public static function actingAs(string $type, Closure $callback): mixed
    {
        $previous = self::$override;
        self::$override = $type;

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }

    public static function resolveType(): string
    {
        if (self::$override !== null) {
            return self::$override;
        }

        if (Auth::id() !== null) {
            return 'user';
        }

        // Antes de este ADR, este descarte devolvía 'system': en la
        // práctica, sin override y fuera de consola, el único caso real
        // que llegaba aquí era una petición HTTP sin sesión — nunca "el
        // sistema" de verdad. 'system' queda reservado a quien lo declare
        // explícitamente con actingAs() (jobs y comandos programados).
        return app()->runningInConsole() ? 'console' : 'anonymous';
    }

    public static function resolveUserId(): ?int
    {
        return Auth::id();
    }
}
