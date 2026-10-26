<?php

namespace Tests\Support;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * REQ-FAM-UNIT (1.14-a): ayudantes de los tests HTTP del módulo. Métodos estáticos de una clase (y no funciones
 * sueltas) para no repetir nombres entre ficheros (CA-060-08). Los alumnos son personas sintéticas creadas por el
 * modelo (`funcional.md §14` punto 3: no hay alta de alumno por API hasta `1.15`, `CLAUDE.md §11`).
 */
final class FamUnitTestHelpers
{
    /**
     * Centro aprovisionado con `fam_unit` contratado y su administrador (que recibe los permisos del módulo por la
     * siembra de `ProvisionTenantDefaults`).
     *
     * @return array{0: Tenant, 1: User}
     */
    public static function setup(string $slug): array
    {
        [$tenant, $admin] = provisionCoreTenant($slug);
        contractModule($tenant, 'fam_unit');

        return [$tenant, $admin];
    }

    public static function url(object $tenant, string $path): string
    {
        return coreApiUrl($tenant->slug, $path);
    }

    /** Persona sintética (el alumno de prueba) creada por el modelo en el contexto del centro. */
    public static function person(object $tenant, string $given = 'Alumno', string $family = 'Prueba'): Person
    {
        // Sin sesión: `created_by` se toma del usuario autenticado, que puede ser de otro centro (FK compuesta).
        resetSessionState();

        return app(TenantContext::class)->runFor($tenant->id, fn (): Person => Person::factory()->create([
            'given_name' => $given,
            'family_name_1' => $family,
        ]));
    }

    /** Llamada autenticada (`$method` en minúsculas: get, post, patch, put, delete). */
    public static function call(object $tenant, User $actor, string $method, string $path, array $body = []): TestResponse
    {
        resetSessionState();

        return test()->actingAs($actor)->json(strtoupper($method), self::url($tenant, $path), $body);
    }

    public static function unit(object $tenant, User $admin, array $body = []): TestResponse
    {
        return self::call($tenant, $admin, 'post', '/family-units', $body);
    }

    /** `public_id` de una unidad creada por la API. */
    public static function unitId(object $tenant, User $admin, ?string $code = null): string
    {
        return self::unit($tenant, $admin, $code === null ? [] : ['code' => $code])->assertCreated()->json('public_id');
    }

    /** Usuario activo con un rol propio que concede exactamente esos permisos (`código => ámbito`). */
    public static function userWith(object $tenant, User $admin, string $local, array $grants): User
    {
        return AcadApiHelpers::userWithScopes($tenant, $admin, $local, $grants);
    }

    /**
     * Usuario activo con varios roles propios (`rol => [permiso => [efecto, ámbito]]`): para probar la combinación
     * multi-rol (`RPERM-007`, `deny` sobrescribe).
     *
     * @param  array<string, array<string, array{0: string, 1: string}>>  $roles
     */
    public static function userWithRoles(object $tenant, User $admin, string $local, array $roles): User
    {
        $roleIds = [];

        foreach ($roles as $code => $permissions) {
            resetSessionState();
            $roleIds[] = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
                'code' => "rol_{$local}_{$code}",
                'name' => "Rol {$local} {$code}",
                'permissions' => array_map(static fn (string $c, array $grant): array => ['code' => $c, 'effect' => $grant[0], 'scope' => $grant[1]], array_keys($permissions), array_values($permissions)),
            ])->assertCreated()->json('public_id');
        }

        resetSessionState();
        test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => "{$local}@example.com",
            'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
            'send_invitation' => false,
            'role_ids' => $roleIds,
        ])->assertCreated();

        $user = app(TenantContext::class)->runFor($tenant->id, function () use ($local): User {
            $user = User::query()->where('email', "{$local}@example.com")->firstOrFail();
            $user->status = UserStatus::Activo;
            $user->save();

            return $user;
        });

        resetSessionState();

        return $user;
    }

    /** Tutor de la unidad a partir de un usuario ya creado. */
    public static function guardian(object $tenant, User $admin, User $user, string $unitId): TestResponse
    {
        return self::call($tenant, $admin, 'post', '/guardians', ['user' => $user->public_id, 'family_unit' => $unitId]);
    }

    /**
     * Usuario con ámbito `unidad_familiar` (lee familias y vínculos) dado de alta como tutor de la unidad.
     *
     * @return array{0: User, 1: string} el usuario y el `public_id` de su faceta de tutor
     */
    public static function familyUser(object $tenant, User $admin, string $local, string $unitId): array
    {
        $user = self::userWith($tenant, $admin, $local, [
            'familia.leer' => 'unidad_familiar',
            'vinculo_tutor.leer' => 'unidad_familiar',
        ]);

        return [$user, self::guardian($tenant, $admin, $user, $unitId)->assertCreated()->json('public_id')];
    }

    public static function addStudent(object $tenant, User $admin, string $unitId, Person $student): TestResponse
    {
        return self::call($tenant, $admin, 'post', "/family-units/{$unitId}/students", ['student_person' => $student->public_id]);
    }

    public static function link(object $tenant, User $admin, string $guardianId, Person $student, array $overrides = []): TestResponse
    {
        return self::call($tenant, $admin, 'post', '/guardian-links', $overrides + [
            'guardian' => $guardianId,
            'student_person' => $student->public_id,
            'relationship' => 'madre',
        ]);
    }

    public static function errorCode(TestResponse $response, string $field): ?string
    {
        return $response->json('errors')[$field][0]['code'] ?? null;
    }

    /** Fila de una tabla del módulo por `public_id`, leída dentro del contexto del centro (RLS). */
    public static function row(object $tenant, string $table, string $publicId): ?object
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): ?object => DB::table($table)->where('public_id', $publicId)->first());
    }

    /** Entradas de `audit_logs` de un tipo auditable, en el contexto del centro. */
    public static function audit(object $tenant, string $type, ?string $event = null): array
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): array => AuditLog::query()
            ->where('auditable_type', $type)
            ->when($event !== null, fn ($q) => $q->where('event', $event))
            ->orderBy('id')
            ->get()
            ->all());
    }
}
