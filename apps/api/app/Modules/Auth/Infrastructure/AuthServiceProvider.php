<?php

namespace App\Modules\Auth\Infrastructure;

use App\Modules\Auth\Domain\AccountLockService;
use App\Modules\Auth\Domain\ClientDescriber;
use App\Modules\Auth\Domain\IpGeolocator;
use App\Modules\Auth\Domain\MfaComplianceDirectory;
use App\Modules\Auth\Domain\MfaPolicy;
use App\Modules\Auth\Domain\MfaVerifier;
use App\Modules\Auth\Domain\Models\AccountLockout;
use App\Modules\Auth\Domain\Models\MfaFactor;
use App\Modules\Auth\Domain\Models\MfaRecoveryCode;
use App\Modules\Auth\Domain\Models\MfaReset as MfaResetModel;
use App\Modules\Auth\Domain\Models\UserKnownDevice;
use App\Modules\Auth\Domain\Models\UserMfaExemption;
use App\Modules\Auth\Domain\Models\UserMfaObligation;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\PasswordPolicy;
use App\Modules\Auth\Domain\PasswordResetTokenRepository;
use App\Modules\Auth\Domain\SessionRevoker;
use App\Modules\Auth\Domain\TotpProvisioner;
use App\Modules\Auth\Domain\UserSessionDirectory;
use App\Modules\Auth\Infrastructure\Console\CloseExpiredLockoutsCommand;
use App\Modules\Auth\Infrastructure\Console\CloseOrphanedUserSessionsCommand;
use App\Modules\Auth\Infrastructure\Console\GrantLockoutPermissionsCommand;
use App\Modules\Auth\Infrastructure\Console\MfaObligationsMaintenanceCommand;
use App\Modules\Auth\Infrastructure\Console\PurgeAuthMaintenanceCommand;
use App\Modules\Auth\Infrastructure\Listeners\MaterializeMfaObligationsForRole;
use App\Modules\Auth\Infrastructure\Listeners\ReconcileMfaAllowedMethodsChange;
use App\Modules\Auth\Infrastructure\Listeners\RevokeSessionsOnUserDeactivated;
use App\Modules\Core\Domain\Events\RoleMfaRequirementChanged;
use App\Modules\Core\Domain\Events\TenantSettingsUpdated;
use App\Modules\Core\Domain\Events\UserDeactivated;
use App\Support\Modules\DeclaresModuleRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
        // OPEN-AUTH-17 aprobada: análisis propio con regex, sin dependencia externa.
        $this->app->bind(ClientDescriber::class, RegexClientDescriber::class);
        // OPEN-AUTH-13 sin resolver: siempre "desconocida" (funcional.md §B.7).
        $this->app->bind(IpGeolocator::class, NullIpGeolocator::class);
        $this->app->bind(UserSessionDirectory::class, EloquentUserSessionDirectory::class);

        // `ADR-041`. Google2FaTotpVerifier es el único adaptador de las
        // dos interfaces — mismo patrón que IpGeolocator/NullIpGeolocator.
        $this->app->bind(MfaVerifier::class, Google2FaTotpVerifier::class);
        $this->app->bind(TotpProvisioner::class, Google2FaTotpVerifier::class);

        // funcional.md §C.4.7 último párrafo, RN-AUTH-62: memoiza por
        // instancia, nunca entre peticiones. scoped() por sí solo NO lo
        // garantiza: fuera de Octane, Laravel no vacía sus instancias
        // scoped en ningún punto del ciclo de vida — en PHP-FPM/FrankenPHP
        // "modo clásico" (ADR-037) da igual, cada petición es un proceso
        // nuevo, pero en un worker de larga vida (Octane, si se adoptara
        // algún día) o dentro de una suite de tests (varias peticiones
        // HTTP simuladas en el mismo proceso, `Kernel::terminate()` de por
        // medio) la memoización sobreviviría entre peticiones y violaría
        // la invariante escrita — hallazgo propio, `CA-AUTH-130/131`.
        // `terminating()` es el mismo enganche que Octane usa para esto.
        $this->app->scoped(MfaPolicy::class, EloquentMfaPolicy::class);
        $this->app->terminating(fn () => $this->app->forgetScopedInstances());

        $this->app->bind(MfaComplianceDirectory::class, EloquentMfaComplianceDirectory::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(app_path('Modules/Auth/Database/migrations'));
        $this->loadViewsFrom(app_path('Modules/Auth/Infrastructure/resources/views'), 'auth');

        // merge=true: se suma al morph map de AppServiceProvider (INV-007).
        Relation::enforceMorphMap([
            'account_lockout' => AccountLockout::class,
            'user_session' => UserSession::class,
            'user_known_device' => UserKnownDevice::class,
            // REQ-AUTH-003 (1.3), datos.md §C.2-§C.6. Hallazgo propio: sin
            // esto, cualquier escritura sobre uno de estos cinco modelos
            // Auditable lanzaba RuntimeException en AuditRecorder — "no
            // está en el morph map" — es decir, dar de alta/confirmar/
            // borrar un factor, generar/consumir códigos de respaldo,
            // restablecer MFA o conceder/revocar una excepción estaban
            // rotos en cuanto el observer intentaba auditar el evento.
            'mfa_factor' => MfaFactor::class,
            'mfa_recovery_code' => MfaRecoveryCode::class,
            'mfa_reset' => MfaResetModel::class,
            'user_mfa_obligation' => UserMfaObligation::class,
            'user_mfa_exemption' => UserMfaExemption::class,
        ]);

        // funcional.md §6.2, issue #8: guarda en todos los entornos, no
        // solo producción — CA-AUTH-001.
        app(SessionEnvironmentGuard::class)->verify();

        // operacion.md §2.1, RN-AUTH-01/RN-AUTH-03: la documentación ya
        // prometía esta guarda ("rechaza cualquier valor por debajo de
        // 12"); no existía en código — hallazgo al escribir la suite de
        // tests de 1.2.
        app(PasswordPolicyEnvironmentGuard::class)->verify();

        // funcional.md §B.2.1 punto 1, §8.2 (1.2b): consumidor de
        // UserDeactivated (REQ-CORE). No existía como listener hasta
        // 1.2b — ver el comentario de la propia clase.
        Event::listen(UserDeactivated::class, RevokeSessionsOnUserDeactivated::class);

        // REQ-AUTH-003 (1.3), funcional.md §C.4.8, §C.4.12 punto 5:
        // consumidores de eventos de REQ-CORE, sin importar nada interno
        // suyo (INV-007).
        Event::listen(RoleMfaRequirementChanged::class, MaterializeMfaObligationsForRole::class);
        Event::listen(TenantSettingsUpdated::class, ReconcileMfaAllowedMethodsChange::class);

        // operacion.md §C.2.1: un totp_window por encima de 2 amplía la
        // ventana de validez de un código TOTP a más de dos minutos y
        // medio (RN-AUTH-58 exige ±1 paso). Guarda de arranque, en todos
        // los entornos — mismo criterio que SessionEnvironmentGuard.
        $this->guardTotpWindow();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PurgeAuthMaintenanceCommand::class,
                CloseExpiredLockoutsCommand::class,
                CloseOrphanedUserSessionsCommand::class,
                GrantLockoutPermissionsCommand::class,
                MfaObligationsMaintenanceCommand::class,
            ]);
        }
    }

    /**
     * `RN-AUTH-58`: la verificación TOTP admite ±1 paso de 30 segundos.
     * `AUTH_MFA_TOTP_WINDOW` por encima de 2 deja de ser "tolerancia de
     * reloj" y pasa a ser una ventana de validez amplia — falla rápido en
     * vez de servir códigos válidos durante minutos.
     */
    private function guardTotpWindow(): void
    {
        $window = (int) config('auth-local.mfa.totp_window');

        if ($window < 0 || $window > 2) {
            throw new RuntimeException(
                "AUTH_MFA_TOTP_WINDOW={$window} fuera de rango (0-2, RN-AUTH-58). ".
                'Un valor mayor amplía la ventana de validez de un código TOTP más allá de lo tolerable.'
            );
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
            // REQ-AUTH-003 (1.3), funcional.md §C.4.10, §C.1.1 punto 9.
            // 'leer': GET /mfa-compliance (vista previa y cumplimiento).
            // 'eliminar': POST /mfa-resets (restablecimiento).
            ['code' => 'mfa.leer', 'resource' => 'mfa', 'action' => 'leer', 'is_special_category' => false],
            ['code' => 'mfa.eliminar', 'resource' => 'mfa', 'action' => 'eliminar', 'is_special_category' => false],
            // REQ-AUTH-003 (1.3b), permisos.md §D.2-§D.5. Recurso propio
            // (no una acción más de `mfa`): la excepción es una entidad
            // con ciclo de vida propio (motivo, caducidad, autor, traza de
            // revocación), no un atributo del usuario. `eliminar` para la
            // revocación por el mismo criterio que `bloqueo_cuenta.eliminar`
            // y `mfa.eliminar`: describe lo que el actor hace desde fuera
            // (retirar algo vigente), no la operación SQL (`RN-AUTH-83`:
            // revocar no borra, deja `revoked_at`/`revoked_by`).
            ['code' => 'exencion_mfa.crear', 'resource' => 'exencion_mfa', 'action' => 'crear', 'is_special_category' => false],
            ['code' => 'exencion_mfa.leer', 'resource' => 'exencion_mfa', 'action' => 'leer', 'is_special_category' => false],
            ['code' => 'exencion_mfa.eliminar', 'resource' => 'exencion_mfa', 'action' => 'eliminar', 'is_special_category' => false],
        ];
    }
}
