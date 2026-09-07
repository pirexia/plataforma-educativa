<?php

namespace App\Providers;

use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\ScopeResolverRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-044 §4.10: el núcleo de autorización granular es infraestructura de
 * framework, igual que el aislamiento de tenant — vive en
 * `App\Support\Authorization`, no en un módulo, y este provider se registra
 * a mano en `bootstrap/providers.php`, igual que `TenancyServiceProvider` y
 * `AuditServiceProvider` (INV-007: si viviera en `App\Modules\Core`, `Auth`
 * tendría que importarlo desde otro módulo para autorizar sus endpoints).
 *
 * `ScopeResolverRegistry` es singleton: un único registro para todo el
 * proceso, donde cada módulo propietario de una entidad de ámbito se
 * apunta desde el `boot()` de su propio ServiceProvider.
 *
 * `PermissionResolver` es `scoped()` (ADR-044 §4.6/§4.7): memoización por
 * petición HTTP, nunca entre peticiones — mismo patrón que
 * `EloquentMfaPolicy` (`AuthServiceProvider`), con el mismo
 * `forgetScopedInstances()` en `terminating()` para que una suite de tests
 * (varias peticiones simuladas en el mismo proceso) no arrastre memoización
 * de una petición a la siguiente.
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ScopeResolverRegistry::class);

        $this->app->scoped(PermissionResolver::class);
        $this->app->terminating(fn () => $this->app->forgetScopedInstances());
    }
}
