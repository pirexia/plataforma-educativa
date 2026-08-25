<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\Models\AccountLockout;
use App\Modules\Auth\Domain\PasswordPolicy;
use App\Modules\Auth\Domain\PasswordResetTokenRepository;
use App\Modules\Auth\Domain\SessionRevoker;
use App\Modules\Auth\Infrastructure\Console\CloseExpiredLockoutsCommand;
use App\Modules\Auth\Infrastructure\Console\GrantLockoutPermissionsCommand;
use App\Modules\Auth\Infrastructure\Console\PurgeAuthMaintenanceCommand;
use App\Support\Modules\DeclaresModuleRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-034 §2, §5, §7: descubierto por ModuleServiceProviderDiscovery, sin
 * registro a mano en bootstrap/providers.php. Declara el catálogo de
 * permisos de permisos.md §3 para que `platform:sync-registry` los
 * materialice.
 */
class AuthServiceProvider extends ServiceProvider implements DeclaresModuleRegistry
{
    public function register(): void
    {
        $this->app->bind(PasswordResetTokenRepository::class, DatabasePasswordResetTokenRepository::class);
        $this->app->bind(PasswordPolicy::class, ConfigPasswordPolicy::class);
        $this->app->bind(SessionRevoker::class, DatabaseSessionRevoker::class);
        $this->app->bind(AccountLockService::class, EloquentAccountLockService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(app_path('Modules/Auth/Database/migrations'));
        $this->loadViewsFrom(app_path('Modules/Auth/Infrastructure/resources/views'), 'auth');

        // merge=true: se suma al morph map de AppServiceProvider (INV-007).
        Relation::enforceMorphMap([
            'account_lockout' => AccountLockout::class,
        ]);

        // funcional.md §6.2, issue #8: guarda en todos los entornos, no
        // solo producción — CA-AUTH-001.
        app(SessionEnvironmentGuard::class)->verify();

        // operacion.md §2.1, RN-AUTH-01/RN-AUTH-03: la documentación ya
        // prometía esta guarda ("rechaza cualquier valor por debajo de
        // 12"); no existía en código — hallazgo al escribir la suite de
        // tests de 1.2.
        app(PasswordPolicyEnvironmentGuard::class)->verify();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PurgeAuthMaintenanceCommand::class,
                CloseExpiredLockoutsCommand::class,
                GrantLockoutPermissionsCommand::class,
            ]);
        }
    }

    public function moduleDescriptor(): array
    {
        return [
            'code' => 'auth',
            'name_key' => 'modules.auth',
            'phase' => '1',
        ];
    }

    /**
     * permisos.md §3. Un solo recurso (`bloqueo_cuenta`), dos acciones —
     * no se declara `crear` (el sistema crea los bloqueos, nunca una
     * persona) ni `actualizar` (un bloqueo no se edita: se crea o se
     * levanta).
     */
    public function declaredPermissions(): array
    {
        return [
            ['code' => 'bloqueo_cuenta.leer', 'resource' => 'bloqueo_cuenta', 'action' => 'leer', 'is_special_category' => false],
            ['code' => 'bloqueo_cuenta.eliminar', 'resource' => 'bloqueo_cuenta', 'action' => 'eliminar', 'is_special_category' => false],
        ];
    }
}
