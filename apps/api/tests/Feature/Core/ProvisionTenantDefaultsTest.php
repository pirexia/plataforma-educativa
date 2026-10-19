<?php

use App\Models\PermissionRole;
use App\Models\Role;
use App\Models\User;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Domain\Models\UserInvitation;
use App\Modules\Core\Domain\TenantAdministrator;
use App\Modules\Core\Domain\TenantInitialSettings;
use App\Modules\Core\Domain\TenantProvisioner;
use App\Modules\Core\Infrastructure\Mail\InvitationMail;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * funcional.md §4.7. Requiere `platform:sync-registry` (el catálogo de
 * `permissions` es FK de `permission_role`) — se ejecuta en beforeEach
 * porque las migraciones de esquema no siembran el catálogo por sí solas.
 */
beforeEach(function (): void {
    Mail::fake();
    $this->artisan('platform:sync-registry')->run();

    $this->tenant = Tenant::factory()->create();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function provisionSmokeTenant(Tenant $tenant): void
{
    app(TenantProvisioner::class)->provision(
        $tenant,
        TenantInitialSettings::defaults(),
        new TenantAdministrator('admin@example.com', 'Ana', 'Perez'),
    );
}

// CA-CORE-040
test('CA-CORE-040: un tenant recién aprovisionado tiene 16 roles predefinidos con name_key informado', function (): void {
    provisionSmokeTenant($this->tenant);

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $roles = Role::query()->get();

        // 16, no 17: "Super Administrador" no es una fila de `roles`
        // (permisos.md §4.5). Ver issue #48 sobre la contradicción con la
        // cifra literal de funcional.md §4.7/CA-CORE-040.
        expect($roles)->toHaveCount(16);

        foreach ($roles as $role) {
            expect($role->is_system)->toBeTrue()
                ->and($role->name_key)->toBe("roles.{$role->code}")
                ->and($role->name)->toBeNull();
        }
    });
});

// CA-CORE-042
test('CA-CORE-042: ninguna fila de permission_role tiene scope distinto de todos', function (): void {
    provisionSmokeTenant($this->tenant);

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $offending = PermissionRole::query()->where('scope', '!=', 'todos')->orWhereNull('scope')->count();

        expect($offending)->toBe(0)
            ->and(PermissionRole::query()->count())->toBeGreaterThan(0);
    });
});

test('administrador_centro recibe los 32 permisos previos más los cuatro de REQ-PERM/permisos.md §5, direccion/secretaria/administrativo su subconjunto', function (): void {
    provisionSmokeTenant($this->tenant);

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $codesFor = fn (string $roleCode) => PermissionRole::query()
            ->whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->pluck('permission_code')->sort()->values()->all();

        // administrador_centro: los 21 de REQ-CORE (20 de 1.1 +
        // rol.actualizar de 1.3, funcional.md §C.2.2) más los 11 de
        // REQ-AUTH (bloqueo_cuenta.leer/eliminar de 1.2, mfa.leer/eliminar
        // de 1.3, exencion_mfa.crear/leer/eliminar de 1.3b —permisos.md
        // §5/§D.6, solo administrador_centro los recibe, RN-AUTH-19/
        // permisos.md §5.1/§D.6.1— más proveedor_identidad.leer/crear/
        // actualizar/eliminar de 1.4b, permisos.md §F.7, mismo criterio)
        // más los cuatro de REQ-PERM/permisos.md §5 (1.5, OPEN-PERM-07):
        // rol.crear, rol.eliminar, rol_datos_especiales.actualizar,
        // permiso_efectivo.leer, más los cinco de REQ-CURSO/permisos.md §4
        // (1.10, OPEN-CURSO-14, CA-CURSO-064), más los cuatro de
        // REQ-ACAD/permisos.md §4 (1.11-b, OPEN-ACAD-09 = X, CA-ACAD-036), más los cuatro de
        // REQ-ACAD/permisos.md §4.1 (1.11d-a, OPEN-ACAD-28 = X + 1, CA-ACAD-097), más
        // reapertura_curso_academico.actualizar de REQ-CURSO/permisos.md §4 (1.11c, ADR-059 §5.2), más los doce de REQ-ACAD/permisos.md §10 (1.12-a: asignacion_docente, espacios y horarios × cuatro acciones).
        expect(Role::where('code', 'administrador_centro')->first()
            ->getConnection()->table('permission_role')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->where('roles.code', 'administrador_centro')->count())->toBe(62);

        foreach (['rol.crear', 'rol.eliminar', 'rol_datos_especiales.actualizar', 'permiso_efectivo.leer'] as $code) {
            expect($codesFor('administrador_centro'))->toContain($code);
        }

        $direccionRole = Role::where('code', 'direccion')->firstOrFail();
        $direccionPermissions = PermissionRole::where('role_id', $direccionRole->id)->pluck('permission_code')->sort()->values()->all();
        expect($direccionPermissions)->toBe(['asignacion_docente.actualizar', 'asignacion_docente.crear', 'asignacion_docente.eliminar', 'asignacion_docente.leer', 'asignacion_rol.leer', 'configuracion.leer', 'configuracion_academica.leer', 'curso_academico.leer', 'curso_historico.leer', 'espacios.leer', 'estructura_academica.leer', 'horarios.actualizar', 'horarios.crear', 'horarios.eliminar', 'horarios.leer', 'modulo.leer', 'rol.leer', 'usuario.leer']);

        // REQ-ACAD/permisos.md §4 y §4.1: docente, tutor_grupo y orientador solo leen la estructura y la configuración académica.
        foreach (['docente', 'tutor_grupo', 'orientador'] as $code) {
            $role = Role::where('code', $code)->firstOrFail();
            expect(PermissionRole::where('role_id', $role->id)->pluck('permission_code')->sort()->values()->all())->toBe(['asignacion_docente.leer', 'configuracion_academica.leer', 'espacios.leer', 'estructura_academica.leer', 'horarios.leer'], $code);
        }
    });
});

// CA-CORE-074
test('CA-CORE-074: ejecutar el aprovisionamiento dos veces seguidas no duplica ni modifica nada', function (): void {
    provisionSmokeTenant($this->tenant);

    $before = app(TenantContext::class)->runFor($this->tenant->id, fn () => [
        'roles' => Role::count(),
        'grants' => PermissionRole::count(),
        'users' => User::count(),
        'invitations' => UserInvitation::count(),
        'settings_id' => TenantSetting::firstOrFail()->id,
    ]);

    provisionSmokeTenant($this->tenant);

    $after = app(TenantContext::class)->runFor($this->tenant->id, fn () => [
        'roles' => Role::count(),
        'grants' => PermissionRole::count(),
        'users' => User::count(),
        'invitations' => UserInvitation::count(),
        'settings_id' => TenantSetting::firstOrFail()->id,
    ]);

    expect($after)->toBe($before);
});

// El correo lo envía SendInvitationEmail (INV-012: ese job es lo que va
// en cola, `core-mail`; QUEUE_CONNECTION=sync en tests lo ejecuta en el
// acto). Mail::to()->send() dentro del job es correcto: la parte pesada
// que no debe ocurrir en la petición HTTP es el job, no la llamada a
// Mail dentro de él — por eso se comprueba con assertSent, no
// assertQueued (que exige Mailable::ShouldQueue, que este no declara).
test('el primer Administrador de Centro queda pendiente, con contraseña no utilizable, y su invitación se encola sin llegar en la petición', function (): void {
    provisionSmokeTenant($this->tenant);

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $user = User::firstOrFail();

        expect($user->status->value)->toBe('pendiente')
            ->and($user->email)->toBe('admin@example.com');

        $invitation = UserInvitation::firstOrFail();
        expect($invitation->isLive())->toBeTrue()
            ->and($invitation->user_id)->toBe($user->id);
    });

    Mail::assertSent(InvitationMail::class);
});
