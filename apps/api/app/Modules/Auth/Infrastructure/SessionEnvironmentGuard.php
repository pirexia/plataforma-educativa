<?php

namespace App\Modules\Auth\Infrastructure;

use RuntimeException;

/**
 * funcional.md §6, operacion.md §2.2, issue #8, ADR-025. Las guardas
 * corren en **todos** los entornos, no solo producción (un entorno de
 * desarrollo con cookie de dominio compartido está probando un modelo de
 * seguridad distinto del que se despliega). Lanzada desde
 * AuthServiceProvider::boot(), antes de que la aplicación sirva ninguna
 * petición — CA-AUTH-001, CA-AUTH-052.
 */
final class SessionEnvironmentGuard
{
    public function verify(): void
    {
        $this->assertDomainIsEmpty();
        $this->assertHttpOnly();
        $this->assertSameSiteNotNone();
        $this->assertPartitionedRequiresSameSiteNone();
        $this->assertSecureInProduction();
        $this->assertLifetimeCoversTenantMaximum();
        $this->assertSessionDriverIsDatabase();
    }

    /**
     * funcional.md §B.2.1 punto 4, RN-AUTH-49, CA-AUTH-103. Con cualquier
     * otro driver la revocación no tiene fila que borrar y respondería
     * `204` sin haber cerrado nada — un requisito de configuración sin
     * guarda no es un requisito, es una esperanza (mismo argumento que
     * `assertDomainIsEmpty()`).
     */
    private function assertSessionDriverIsDatabase(): void
    {
        if (config('session.driver') !== 'database') {
            throw new RuntimeException(
                'SESSION_DRIVER debe ser "database" en todos los entornos: con cualquier otro valor, '.
                'el panel de sesiones activas y su revocación no tendrían nada que borrar '.
                '(docs/modulos/REQ-AUTH/funcional.md §B.2.1).'
            );
        }
    }

    /**
     * Issue #8: host-only, sin excepción. Fijar SESSION_DOMAIN haría que
     * el navegador enviara la cookie de un tenant al host de otro
     * (funcional.md §6.1).
     */
    private function assertDomainIsEmpty(): void
    {
        $domain = config('session.domain');

        if ($domain !== null && $domain !== '') {
            throw new RuntimeException(
                'SESSION_DOMAIN debe quedar vacío en todos los entornos: fijarlo rompe el aislamiento '.
                'de sesión entre tenants (docs/modulos/REQ-AUTH/funcional.md §6, issue #8).'
            );
        }
    }

    private function assertHttpOnly(): void
    {
        if (config('session.http_only') !== true) {
            throw new RuntimeException(
                'SESSION_HTTP_ONLY debe ser true siempre (RN-AUTH-26, docs/modulos/REQ-AUTH/funcional.md §6).'
            );
        }
    }

    /**
     * RN-AUTH-27: Lax, nunca None. Strict tampoco se admite (rompería el
     * retorno desde un enlace de correo, funcional.md §5.28), así que solo
     * "lax" o "strict" pasan aquí — pero solo "lax" es la política
     * elegida; se rechaza explícitamente "none" por ser el valor que abre
     * la puerta a compartir la cookie entre sitios.
     */
    private function assertSameSiteNotNone(): void
    {
        if (config('session.same_site') === 'none') {
            throw new RuntimeException(
                'SESSION_SAME_SITE=none está prohibido (RN-AUTH-27, docs/modulos/REQ-AUTH/funcional.md §6).'
            );
        }
    }

    private function assertPartitionedRequiresSameSiteNone(): void
    {
        if (config('session.partitioned') === true) {
            throw new RuntimeException(
                'SESSION_PARTITIONED_COOKIE solo tiene sentido con SameSite=None, que está prohibido '.
                '(docs/modulos/REQ-AUTH/operacion.md §2.2).'
            );
        }
    }

    private function assertSecureInProduction(): void
    {
        if (app()->environment('production') && config('session.secure') !== true) {
            throw new RuntimeException(
                'SESSION_SECURE_COOKIE debe ser true en producción (docs/modulos/REQ-AUTH/operacion.md §2.2).'
            );
        }
    }

    /**
     * CA-AUTH-052, RN-AUTH-30: si el corte global del framework fuera
     * menor que el máximo configurable por tenant, el framework mataría
     * sesiones antes que la regla del centro — el ajuste del tenant no
     * significaría nada.
     */
    private function assertLifetimeCoversTenantMaximum(): void
    {
        $lifetime = (int) config('session.lifetime');
        $max = (int) config('auth-local.session_timeout_max_minutes');

        if ($lifetime < $max) {
            throw new RuntimeException(
                "SESSION_LIFETIME ({$lifetime} min) debe ser mayor o igual que AUTH_SESSION_TIMEOUT_MAX_MINUTES ".
                "({$max} min) — RN-AUTH-30, docs/modulos/REQ-AUTH/funcional.md §4.6."
            );
        }
    }
}
