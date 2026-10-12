<?php

namespace Tests\Support;

use App\Models\User;
use App\Models\UserStatus;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * REQ-ACAD (1.11-b): ayudantes de los tests HTTP del módulo. Métodos
 * estáticos de una clase (y no funciones sueltas) para no repetir nombres
 * entre ficheros de test (CA-060-08).
 */
final class AcadApiHelpers
{
    /**
     * Centro aprovisionado con `acad` contratado, su administrador y un curso
     * en el estado pedido.
     *
     * @return array{0: Tenant, 1: User, 2: AcademicYear}
     */
    public static function setup(string $slug, AcademicYearStatus $status = AcademicYearStatus::Activo): array
    {
        [$tenant, $admin] = provisionCoreTenant($slug);
        contractModule($tenant, 'acad');

        return [$tenant, $admin, CursoTestHelpers::year($tenant, '2026-2027', $status)];
    }

    public static function url(object $tenant, string $path): string
    {
        return coreApiUrl($tenant->slug, $path);
    }

    /**
     * Usuario activo con un rol personalizado que concede EXACTAMENTE los
     * permisos indicados (ámbito `todos`).
     *
     * @param  list<string>  $codes
     */
    public static function userWith(object $tenant, User $admin, string $local, array $codes): User
    {
        resetSessionState();
        $roleId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/roles'), [
            'code' => "rol_{$local}",
            'name' => "Rol {$local}",
            'permissions' => array_map(static fn (string $c): array => ['code' => $c, 'effect' => 'allow', 'scope' => 'todos'], $codes),
        ])->assertCreated()->json('public_id');

        resetSessionState();
        test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/users'), [
            'email' => "{$local}@example.com",
            'person' => ['given_name' => 'Persona', 'family_name_1' => 'Prueba'],
            'send_invitation' => false,
            'role_ids' => [$roleId],
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

    /** Fila de una tabla del módulo por `public_id`, leída dentro del contexto del centro (RLS). */
    public static function row(object $tenant, string $table, string $publicId): ?object
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): ?object => \Illuminate\Support\Facades\DB::table($table)->where('public_id', $publicId)->first());
    }

    /** Idiomas del centro (`PATCH /tenant/settings`). */
    public static function setLocales(object $tenant, User $admin, string $default, array $active): void
    {
        resetSessionState();
        test()->actingAs($admin)->patchJson(coreApiUrl($tenant->slug, '/tenant/settings'), [
            'regional' => ['default_locale' => $default, 'active_locales' => $active],
        ])->assertOk();
        resetSessionState();
    }

    /**
     * Árbol completo por la API: etapa, curso de etapa, grupo, subgrupo y
     * asignatura. Devuelve los `public_id`.
     *
     * @return array{level: string, course: string, group: string, subgroup: string, subject: string}
     */
    public static function tree(object $tenant, User $admin, AcademicYear $year, string $suffix = ''): array
    {
        $post = function (string $path, array $body) use ($tenant, $admin): string {
            resetSessionState();

            return test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, $path), $body)->assertCreated()->json('public_id');
        };

        $level = $post('/education-levels', ['academic_year' => $year->public_id, 'code' => "ESO{$suffix}", 'name_translations' => ['es-ES' => 'ESO'], 'stage' => 'eso', 'regime' => 'concertado']);
        $course = $post('/courses', ['education_level' => $level, 'code' => "1ESO{$suffix}", 'name_translations' => ['es-ES' => 'Primero']]);
        $group = $post('/groups', ['course' => $course, 'code' => "A{$suffix}", 'name' => 'Grupo A']);
        $subgroup = $post("/groups/{$group}/subgroups", ['code' => "D1{$suffix}", 'name' => 'Desdoble']);
        $subject = $post('/subjects', ['course' => $course, 'code' => "MAT{$suffix}", 'name_translations' => ['es-ES' => 'Matemáticas']]);

        return compact('level', 'course', 'group', 'subgroup', 'subject');
    }
}
