<?php

namespace App\Modules\Core\Application;

use App\Models\PermissionRole;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\Events\UserCreated;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 */
final class ProvisionTenantDefaults
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

    private const ADMIN_CENTRO_PERMISSIONS = [
        'usuario.leer', 'usuario.crear', 'usuario.actualizar', 'usuario.eliminar', 'usuario.importar', 'usuario.exportar',
        'invitacion.leer', 'invitacion.crear', 'invitacion.eliminar',
        'asignacion_rol.leer', 'asignacion_rol.crear', 'asignacion_rol.eliminar',
        // REQ-AUTH/funcional.md §C.2.2, §C.16 (1.3): 'rol.actualizar' es
        // el permiso de PATCH /roles/{public_id} acotado a mfa_required.
        'rol.leer', 'rol.actualizar', 'permiso.leer',
        'configuracion.leer', 'configuracion.actualizar',
        'modulo.leer', 'modulo.actualizar',
        'auditoria.leer', 'auditoria.exportar',
        // REQ-AUTH/permisos.md §5: solo administrador_centro (§5.1).
        'bloqueo_cuenta.leer', 'bloqueo_cuenta.eliminar',
        // REQ-AUTH/funcional.md §C.4.10, §C.1.1 punto 9 (1.3).
        'mfa.leer', 'mfa.eliminar',
    ];

    /** @var array<string, list<string>> */
    private const CORE_PERMISSION_GRANTS = [
        'direccion' => ['usuario.leer', 'rol.leer', 'asignacion_rol.leer', 'configuracion.leer', 'modulo.leer'],
        'secretaria' => ['usuario.leer', 'invitacion.leer'],
        'administrativo' => ['usuario.leer'],
    ];

    public function __construct(
        private readonly IssueUserInvitation $invitations,
        private readonly TenantContext $tenantContext,
    ) {}

    public function provision(Tenant $tenant, string $adminEmail, string $adminGivenName, string $adminFamilyName): void
    {
        $this->tenantContext->runFor($tenant->id, function () use ($tenant, $adminEmail, $adminGivenName, $adminFamilyName): void {
            if (TenantSetting::query()->exists()) {
                return;
            }

            AuditActor::actingAs('console', function () use ($tenant, $adminEmail, $adminGivenName, $adminFamilyName): void {
                DB::transaction(function () use ($tenant, $adminEmail, $adminGivenName, $adminFamilyName): void {
                    TenantSetting::create([]);

                    $roleIds = $this->seedRoles();
                    $this->seedPermissionGrants($roleIds);

                    $user = $this->createAdministrator($adminEmail, $adminGivenName, $adminFamilyName);
                    $user->roles()->attach($roleIds['administrador_centro']);

                    event(new UserCreated($tenant->id, $user->public_id));

                    $this->invitations->issue($user, $tenant->slug, $tenant->name);
                });
            });
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

    private function createAdministrator(string $email, string $givenName, string $familyName): User
    {
        $person = Person::create([
            'given_name' => $givenName,
            'family_name_1' => $familyName,
            'contact_email' => $email,
            'locale' => 'es-ES',
        ]);

        return User::create([
            'person_id' => $person->id,
            'email' => $email,
            // No utilizable (RN-CORE-04/funcional.md §1.4): nadie puede
            // iniciar sesión con esta contraseña. El cast 'hashed' de User
            // la hashea antes de guardar.
            'password' => Str::password(48),
            'status' => 'pendiente',
        ]);
    }
}
