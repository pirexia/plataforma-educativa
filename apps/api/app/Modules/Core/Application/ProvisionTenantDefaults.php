<?php

namespace App\Modules\Core\Application;

use App\Models\PermissionRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\Events\UserCreated;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Domain\TenantAdministrator;
use App\Modules\Core\Domain\TenantInitialSettings;
use App\Modules\Core\Domain\TenantProvisioner;
use App\Modules\Core\Domain\TenantProvisioningOutcome;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * funcional.md §4.7. Sin este flujo, 1.1 no tiene ni un solo usuario con
 * el que probarse. Idempotente (CA-CORE-074): si el tenant ya tiene
 * `tenant_settings`, la segunda ejecución no toca nada.
 *
 * Los 16 roles (no 17: "Super Administrador" no es una fila de `roles`,
 * permisos.md §4.5 — issue #48, contradicción de funcional.md §4.7/
 * CA-CORE-040 con la cifra "17") y sus atributos de `permisos.md` §4.2/
 * §4.3, y la matriz de concesión de `permisos.md` §4.1 (RN-CORE-22: todo
 * `scope = 'todos'`).
 *
 * ADR-048 §4.4: implementa `TenantProvisioner`, la superficie pública que
 * `REQ-BO` (1.6b) consume sin importar código interno de este módulo
 * (INV-007). **No se mueve ni se renombra** a `Infrastructure\Eloquent*`:
 * no es un adaptador de persistencia, es un servicio de aplicación que
 * orquesta una transacción, un contexto de tenant y un actor de
 * auditoría — la asimetría con sus cinco hermanas es deliberada.
 */
final class ProvisionTenantDefaults implements TenantProvisioner
{
    /** @var array<string, array{mfa_required: bool, special_data_access: bool}> */
    private const ROLE_ATTRIBUTES = [
        'administrador_centro' => ['mfa_required' => true, 'special_data_access' => false],
        'direccion' => ['mfa_required' => false, 'special_data_access' => false],
        'secretaria' => ['mfa_required' => false, 'special_data_access' => false],
        'administrativo' => ['mfa_required' => false, 'special_data_access' => false],
        'docente' => ['mfa_required' => false, 'special_data_access' => false],
        'tutor_grupo' => ['mfa_required' => false, 'special_data_access' => false],
        'orientador' => ['mfa_required' => false, 'special_data_access' => true],
        'coordinador_bienestar' => ['mfa_required' => false, 'special_data_access' => true],
        'estudiante' => ['mfa_required' => false, 'special_data_access' => false],
        'tutor_legal' => ['mfa_required' => false, 'special_data_access' => false],
        'responsable_economico' => ['mfa_required' => false, 'special_data_access' => false],
        'bibliotecario' => ['mfa_required' => false, 'special_data_access' => false],
        'monitor_extraescolares' => ['mfa_required' => false, 'special_data_access' => false],
        'personal_sanitario' => ['mfa_required' => false, 'special_data_access' => true],
        'conserjeria_pas' => ['mfa_required' => false, 'special_data_access' => false],
        'soporte_plataforma' => ['mfa_required' => true, 'special_data_access' => false],
    ];

    /**
     * REQ-PERM/permisos.md §5, operacion.md §4.3 (1.5, OPEN-PERM-07): los
     * cuatro permisos nuevos de este paso, a `administrador_centro` y a
     * nadie más. Constante propia (no inline en `ADMIN_CENTRO_PERMISSIONS`)
     * para que `GrantRoleAdministrationCommand` (operacion.md §4.3, el
     * comando de migración de datos para tenants ya existentes) conceda
     * exactamente la misma lista, en un solo sitio, por los dos caminos.
     *
     * `rol_datos_especiales.actualizar` incluido: es la decisión que evita
     * el bloqueo sin salida (conceder un permiso exige poseerlo,
     * RPERM-013), aunque `administrador_centro` no pueda ejercerlo por sí
     * mismo (le falta `special_data_access`, a propósito — §5.5).
     */
    public const ROLE_ADMINISTRATION_PERMISSIONS = [
        'rol.crear', 'rol.eliminar', 'rol_datos_especiales.actualizar', 'permiso_efectivo.leer',
    ];

    private const ADMIN_CENTRO_PERMISSIONS = [
        'usuario.leer', 'usuario.crear', 'usuario.actualizar', 'usuario.eliminar', 'usuario.importar', 'usuario.exportar',
        'invitacion.leer', 'invitacion.crear', 'invitacion.eliminar',
        'asignacion_rol.leer', 'asignacion_rol.crear', 'asignacion_rol.eliminar',
        // REQ-AUTH/funcional.md §C.2.2, §C.16 (1.3): 'rol.actualizar' es
        // el permiso de PATCH /roles/{public_id} acotado a mfa_required.
        'rol.leer', 'rol.actualizar',
        ...self::ROLE_ADMINISTRATION_PERMISSIONS,
        'permiso.leer',
        'configuracion.leer', 'configuracion.actualizar',
        'modulo.leer', 'modulo.actualizar',
        'auditoria.leer', 'auditoria.exportar',
        // REQ-AUTH/permisos.md §5: solo administrador_centro (§5.1).
        'bloqueo_cuenta.leer', 'bloqueo_cuenta.eliminar',
        // REQ-AUTH/funcional.md §C.4.10, §C.1.1 punto 9 (1.3).
        'mfa.leer', 'mfa.eliminar',
        // REQ-AUTH/permisos.md §D.6 (1.3b). Solo administrador_centro,
        // por el mismo argumento reforzado de §D.6.1: conceder una
        // excepción deja a alguien sin segundo factor y sin obligación
        // hasta 90 días.
        'exencion_mfa.crear', 'exencion_mfa.leer', 'exencion_mfa.eliminar',
        // REQ-AUTH/permisos.md §F.7 (1.4b). Solo administrador_centro:
        // quien configura el proveedor de identidad decide de quién se
        // fía el sistema para dejar entrar — la concesión de acceso más
        // amplia que un rol de centro puede hacer.
        'proveedor_identidad.leer', 'proveedor_identidad.crear',
        'proveedor_identidad.actualizar', 'proveedor_identidad.eliminar',
    ];

    /** @var array<string, list<string>> */
    private const CORE_PERMISSION_GRANTS = [
        'direccion' => ['usuario.leer', 'rol.leer', 'asignacion_rol.leer', 'configuracion.leer', 'modulo.leer'],
        'secretaria' => ['usuario.leer', 'invitacion.leer'],
        'administrativo' => ['usuario.leer'],
    ];

    /**
     * ADR-048 §4.5: columnas "operativas" de `tenant_settings` que
     * `provisionFromTemplate()` copia del origen. Nunca identidad fiscal
     * ni marca (funcional.md §5.6.2): esta lista vive aquí porque
     * `REQ-CORE` es quien conoce esas columnas.
     */
    private const OPERATIONAL_SETTINGS_ATTRIBUTES = [
        'default_locale', 'active_locales', 'timezone', 'currency', 'autonomous_community',
        'session_timeout_minutes', 'mfa_allowed_methods', 'mfa_grace_period_days',
    ];

    public function __construct(
        private readonly IssueUserInvitation $invitations,
        private readonly TenantContext $tenantContext,
    ) {}

    public function provision(Tenant $tenant, TenantInitialSettings $settings, TenantAdministrator $administrator): TenantProvisioningOutcome
    {
        return $this->tenantContext->runFor($tenant->id, function () use ($tenant, $settings, $administrator): TenantProvisioningOutcome {
            if (TenantSetting::query()->exists()) {
                return TenantProvisioningOutcome::AlreadyProvisioned;
            }

            AuditActor::actingAs('console', function () use ($tenant, $settings, $administrator): void {
                DB::transaction(function () use ($tenant, $settings, $administrator): void {
                    // ADR-048 §5.1: tenant_settings se escribe ANTES que la
                    // Person y ANTES que la invitación — al revés,
                    // IssueUserInvitation lee el locale por defecto que
                    // acaba de dejar de existir y el correo sale en
                    // español sin que nada falle visiblemente.
                    TenantSetting::create([
                        'default_locale' => $settings->defaultLocale,
                        'active_locales' => $settings->activeLocales,
                        'timezone' => $settings->timezone,
                        'currency' => $settings->currency,
                        'autonomous_community' => $settings->autonomousCommunity,
                    ]);

                    $roleIds = $this->seedRoles();
                    $this->seedPermissionGrants($roleIds);

                    $user = $this->createAdministrator($administrator, $settings->defaultLocale);
                    $user->roles()->attach($roleIds['administrador_centro']);

                    event(new UserCreated($tenant->id, $user->public_id));

                    $this->invitations->issue($user, $tenant->slug, $tenant->name);
                });
            });

            return TenantProvisioningOutcome::Provisioned;
        });
    }

    /**
     * ADR-048 §4.5, §5.3, funcional.md §5.6.2, §5.6.4. La lectura del
     * origen ocurre dentro de la misma transacción que la escritura del
     * destino, en un único punto en el tiempo: se entra en el contexto del
     * origen solo para leer (`runFor`, RLS normal — nunca `runAsPlatform`,
     * que exigiría un administrador de plataforma autenticado que un
     * trabajo en cola no tiene, y haría que `AuditRecorder` lanzara bajo
     * el propósito `Mantenimiento`), y se escribe enteramente dentro del
     * contexto del destino, donde `tenant_id` se rellena solo y la
     * auditoría de tenant se comporta con normalidad.
     */
    public function provisionFromTemplate(Tenant $source, Tenant $target, TenantAdministrator $administrator): TenantProvisioningOutcome
    {
        return DB::transaction(function () use ($source, $target, $administrator): TenantProvisioningOutcome {
            $alreadyProvisioned = $this->tenantContext->runFor(
                $target->id,
                fn (): bool => TenantSetting::query()->exists(),
            );

            if ($alreadyProvisioned) {
                return TenantProvisioningOutcome::AlreadyProvisioned;
            }

            /** @var array{settings: TenantSetting, roles: Collection<int, Role>, grants: Collection<int, PermissionRole>} $template */
            $template = $this->tenantContext->runFor($source->id, function () use ($source): array {
                $settings = TenantSetting::query()->first();

                if ($settings === null) {
                    throw new RuntimeException(
                        "El tenant origen (id={$source->id}) no tiene tenant_settings: no se puede clonar."
                    );
                }

                return [
                    'settings' => $settings,
                    'roles' => Role::query()->get(),
                    'grants' => PermissionRole::query()->get(),
                ];
            });

            $this->tenantContext->runFor($target->id, function () use ($template, $administrator, $target): void {
                AuditActor::actingAs('console', function () use ($template, $administrator, $target): void {
                    $sourceSettings = $template['settings'];

                    TenantSetting::create(array_intersect_key(
                        $sourceSettings->only(self::OPERATIONAL_SETTINGS_ATTRIBUTES),
                        array_flip(self::OPERATIONAL_SETTINGS_ATTRIBUTES),
                    ));

                    $roleIdByCode = [];
                    $roleCodeById = [];

                    foreach ($template['roles'] as $sourceRole) {
                        $newRole = Role::create([
                            'code' => $sourceRole->code,
                            'name_key' => $sourceRole->name_key,
                            'name' => $sourceRole->name,
                            'is_system' => $sourceRole->is_system,
                            'mfa_required' => $sourceRole->mfa_required,
                            'special_data_access' => $sourceRole->special_data_access,
                        ]);

                        $roleIdByCode[$sourceRole->code] = $newRole->id;
                        $roleCodeById[$sourceRole->id] = $sourceRole->code;
                    }

                    foreach ($template['grants'] as $grant) {
                        $code = $roleCodeById[$grant->role_id] ?? null;

                        if ($code === null) {
                            continue;
                        }

                        PermissionRole::create([
                            'role_id' => $roleIdByCode[$code],
                            'permission_code' => $grant->permission_code,
                            'effect' => $grant->effect,
                            'scope' => $grant->scope,
                        ]);
                    }

                    $administratorRoleId = $roleIdByCode['administrador_centro'] ?? throw new RuntimeException(
                        'El tenant origen no tiene el rol administrador_centro: no se puede clonar.'
                    );

                    $user = $this->createAdministrator($administrator, $sourceSettings->default_locale);
                    $user->roles()->attach($administratorRoleId);

                    event(new UserCreated($target->id, $user->public_id));

                    $this->invitations->issue($user, $target->slug, $target->name);
                });
            });

            return TenantProvisioningOutcome::Provisioned;
        });
    }

    /**
     * @return array<string, int>
     */
    private function seedRoles(): array
    {
        $roleIds = [];

        foreach (self::ROLE_ATTRIBUTES as $code => $attributes) {
            $role = Role::create([
                'code' => $code,
                'name_key' => "roles.{$code}",
                'name' => null,
                'is_system' => true,
                'mfa_required' => $attributes['mfa_required'],
                'special_data_access' => $attributes['special_data_access'],
            ]);

            $roleIds[$code] = $role->id;
        }

        return $roleIds;
    }

    /**
     * @param  array<string, int>  $roleIds
     */
    private function seedPermissionGrants(array $roleIds): void
    {
        $grants = [...self::CORE_PERMISSION_GRANTS, 'administrador_centro' => self::ADMIN_CENTRO_PERMISSIONS];

        foreach ($grants as $roleCode => $permissionCodes) {
            foreach ($permissionCodes as $permissionCode) {
                PermissionRole::create([
                    'role_id' => $roleIds[$roleCode],
                    'permission_code' => $permissionCode,
                    'effect' => 'allow',
                    'scope' => 'todos',
                ]);
            }
        }
    }

    /**
     * ADR-048 §5.1: el idioma del primer administrador deja de ser
     * `es-ES` literal — pasa a ser el del centro. `IssueUserInvitation`
     * solo consulta `defaultLocale()` cuando `person.locale` es nulo, así
     * que sin este cambio la invitación de un centro alemán seguiría
     * saliendo siempre en español.
     */
    private function createAdministrator(TenantAdministrator $administrator, string $locale): User
    {
        $person = Person::create([
            'given_name' => $administrator->givenName,
            'family_name_1' => $administrator->familyName,
            'contact_email' => $administrator->email,
            'locale' => $locale,
        ]);

        return User::create([
            'person_id' => $person->id,
            'email' => $administrator->email,
            // No utilizable (RN-CORE-04/funcional.md §1.4): nadie puede
            // iniciar sesión con esta contraseña. El cast 'hashed' de User
            // la hashea antes de guardar.
            'password' => Str::password(48),
            'status' => 'pendiente',
        ]);
    }
}
