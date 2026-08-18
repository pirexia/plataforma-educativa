<?php

namespace App\Support\Audit;

use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * ADR-035 §8 (auditable_type de ADR-034 §3): resuelve actor_user_id y
 * actor_type. Sin REQ-AUTH todavía (1.2), Auth::id() nunca devuelve nada
 * en HTTP real — el caso 'user' se cubrirá entonces sin tocar esta clase.
 * override() da a REQ-ONB (importación) y a TenantContext::runAsPlatform()
 * un sitio donde declarar 'import'/'platform' explícitamente cuando
 * existan, en vez de que este código intente adivinarlo.
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

        return app()->runningInConsole() ? 'console' : 'system';
    }

    public static function resolveUserId(): ?int
    {
        return Auth::id();
    }
}
