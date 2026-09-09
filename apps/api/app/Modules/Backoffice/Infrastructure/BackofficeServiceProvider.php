<?php

namespace App\Modules\Backoffice\Infrastructure;

use App\Modules\Auth\Domain\MfaVerifier;
use App\Modules\Auth\Domain\TotpProvisioner;
use App\Modules\Auth\Infrastructure\Google2FaTotpVerifier;
use App\Modules\Backoffice\Infrastructure\Console\AllowIpCommand;
use App\Modules\Backoffice\Infrastructure\Console\CloseOrphanedPlatformSessionsCommand;
use App\Modules\Backoffice\Infrastructure\Console\CreateAdminCommand;
use App\Modules\Backoffice\Infrastructure\Console\ExpireDualAuthorizationsCommand;
use App\Modules\Backoffice\Infrastructure\Console\PurgePlatformMfaChallengesCommand;
use App\Modules\Backoffice\Infrastructure\Console\ResetMfaCommand;
use App\Support\Tenancy\PlatformAccessCheck;
use Illuminate\Support\ServiceProvider;

/**
 * ADR-046 §4.1, §6.3. Descubierto por `ModuleServiceProviderDiscovery`.
 * `REQ-BO` no es un módulo activable (funcional.md §10): no implementa
 * `DeclaresModuleRegistry` — no tiene fila en `modules` ni catálogo de
 * `permissions` de `REQ-PERM` (permisos.md §2: su mapa de capacidades
 * vive en código, no en `platform:sync-registry`).
 */
class BackofficeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ADR-046 §6.3: sustituye el enlace por defecto
        // (`DefaultPlatformAccessCheck`, denegaba los dos propósitos de
        // backoffice) por la implementación real.
        $this->app->singleton(PlatformAccessCheck::class, BackofficeAccessCheck::class);

        // funcional.md §2.3: se reutiliza el mecanismo (ADR-041), no el
        // almacenamiento — misma implementación que enlaza `AuthServiceProvider`.
        $this->app->bind(MfaVerifier::class, Google2FaTotpVerifier::class);
        $this->app->bind(TotpProvisioner::class, Google2FaTotpVerifier::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(app_path('Modules/Backoffice/Database/migrations'));

        // Issue #173. Precedente: CoreServiceProvider::boot() registra
        // 'core' para InvitationMail. Aquí, la vista del correo de
        // invitación de un platform_admin.
        $this->loadViewsFrom(app_path('Modules/Backoffice/Infrastructure/resources/views'), 'backoffice');

        // Ninguno de los modelos de este módulo implementa Auditable
        // (RN-BO-29 a RN-BO-32: el rastro es `admin_action_logs`, no
        // `audit_logs`) ni usa una relación polimórfica propia, así que
        // no hace falta registrar nada en el morph map de ADR-034 §3 —
        // ese registro es solo para lo que `AuditRecorder`/`morphTo()`
        // necesitan resolver.
        if ($this->app->runningInConsole()) {
            $this->commands([
                AllowIpCommand::class,
                CreateAdminCommand::class,
                ResetMfaCommand::class,
                CloseOrphanedPlatformSessionsCommand::class,
                PurgePlatformMfaChallengesCommand::class,
                ExpireDualAuthorizationsCommand::class,
            ]);
        }
    }
}
