<?php

namespace Tests\Support;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Models\UserStatus;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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
        // Sin sesión del centro anterior: `created_by` se toma del usuario autenticado (FK compuesta con el tenant).
        resetSessionState();

        [$tenant, $admin] = provisionCoreTenant($slug);
        contractModule($tenant, 'fam_unit');

        return [$tenant, $admin];
    }

    public static function url(object $tenant, string $path): string
    {
        return coreApiUrl($tenant->slug, $path);
    }

    /** Descontrata `fam_unit` del centro (`module_subscriptions` solo la escribe la plataforma) y olvida la caché. */
    public static function disableModule(object $tenant): void
    {
        app(TenantContext::class)->runFor($tenant->id, function (): void {
            DB::connection('pgsql_platform')->table('module_subscriptions')->where('module_code', 'fam_unit')->update(['enabled' => false]);
            Cache::forget('modules:fam_unit:enabled');
        });
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

    /** Idiomas del centro (`PATCH /tenant/settings`): por defecto solo `es-ES` está activo. */
    public static function setLocales(object $tenant, User $admin, string $default, array $active): void
    {
        AcadApiHelpers::setLocales($tenant, $admin, $default, $active);
    }

    // ─── Consentimiento de imagen (1.14-b) ───

    /** `kind` del texto legal que corresponde a un nivel (`RN-FAM-UNIT-69`). */
    public static function kindOf(string $level): string
    {
        return $level === 'web' ? 'consentimiento_imagen_web' : 'consentimiento_imagen_redes';
    }

    /**
     * Congela el reloj en un instante fijo. `occurred_at` y `legal_guardian_verified_at` tienen precisión de segundos
     * (`timestamptz(0)`) y una decisión solo cuenta si es **posterior** a la verificación (`RN-FAM-UNIT-72`): los
     * escenarios avanzan el reloj con `tick()` entre pasos.
     */
    public static function freeze(string $at = '2026-10-27 09:00:00'): void
    {
        test()->travelTo(CarbonImmutable::parse($at));
    }

    /** Avanza el reloj congelado (por defecto, dos segundos, para quedar siempre por encima del segundo anterior). */
    public static function tick(int $seconds = 2): void
    {
        test()->travelTo(CarbonImmutable::now()->addSeconds($seconds));
    }

    /**
     * Crea y publica una versión del texto del consentimiento de un nivel; devuelve su `public_id`. Publicar otra del
     * mismo tipo retira la anterior.
     *
     * @param  array<string, string>|null  $title
     * @param  array<string, string>|null  $body
     */
    public static function publishText(object $tenant, User $admin, string $level, ?array $title = null, ?array $body = null): string
    {
        $id = self::draftText($tenant, $admin, $level, $title, $body);
        self::call($tenant, $admin, 'post', "/terms-versions/{$id}/publish")->assertOk();

        return $id;
    }

    /**
     * @param  array<string, string>|null  $title
     * @param  array<string, string>|null  $body
     */
    public static function draftText(object $tenant, User $admin, string $level, ?array $title = null, ?array $body = null): string
    {
        return self::call($tenant, $admin, 'post', '/terms-versions', [
            'kind' => self::kindOf($level),
            'title_translations' => $title ?? ['es-ES' => "Consentimiento {$level}"],
            'body_translations' => $body ?? ['es-ES' => "Texto legal del nivel {$level}"],
        ])->assertCreated()->json('public_id');
    }

    /** Registra una decisión (`POST /image-consent-decisions`). */
    public static function decide(object $tenant, User $actor, array $body): TestResponse
    {
        return self::call($tenant, $actor, 'post', '/image-consent-decisions', $body);
    }

    /**
     * El estado calculado de un alumno en un nivel, leído como administrador (`GET /image-consents`).
     */
    public static function consentState(object $tenant, User $admin, Person $student, string $level): ?string
    {
        $rows = self::call($tenant, $admin, 'get', "/image-consents?student_person={$student->public_id}&level={$level}")->assertOk()->json('data');

        return $rows[0]['state'] ?? null;
    }

    /**
     * Familia con un alumno en su unidad y dos representantes legales activos (R1 y R2, cada uno con usuario y permisos
     * `unidad_familiar` de lectura y decisión de imagen), con los textos publicados de los dos niveles. El reloj queda
     * congelado y avanzado tras el alta de los vínculos, de modo que las decisiones posteriores cuentan.
     *
     * @return array{tenant: object, admin: User, unit: string, student: Person, r1: User, g1: string, l1: string, r2: User, g2: string, l2: string}
     */
    public static function consentFamily(string $slug, bool $twoGuardians = true): array
    {
        [$tenant, $admin] = self::setup($slug);
        self::freeze();

        $unit = self::unitId($tenant, $admin);
        $student = self::person($tenant, 'Alumno', 'Imagen');
        self::addStudent($tenant, $admin, $unit, $student)->assertCreated();

        $r1 = self::consentGuardian($tenant, $admin, 'r1', $unit);
        $g1 = self::guardian($tenant, $admin, $r1, $unit)->assertCreated()->json('public_id');
        $l1 = self::link($tenant, $admin, $g1, $student, ['is_legal_guardian' => true, 'relationship' => 'madre'])->assertCreated()->json('public_id');

        $r2 = $g2 = $l2 = null;

        if ($twoGuardians) {
            $r2 = self::consentGuardian($tenant, $admin, 'r2', $unit);
            $g2 = self::guardian($tenant, $admin, $r2, $unit)->assertCreated()->json('public_id');
            $l2 = self::link($tenant, $admin, $g2, $student, ['is_legal_guardian' => true, 'relationship' => 'padre'])->assertCreated()->json('public_id');
        }

        self::publishText($tenant, $admin, 'web');
        self::publishText($tenant, $admin, 'redes_sociales');
        self::tick();

        return compact('tenant', 'admin', 'unit', 'student', 'r1', 'g1', 'l1', 'r2', 'g2', 'l2');
    }

    /** Usuario con ámbito `unidad_familiar` de lectura de familia, vínculos y consentimiento, y decisión de imagen. */
    public static function consentGuardian(object $tenant, User $admin, string $local, string $unitId): User
    {
        return self::userWith($tenant, $admin, $local, [
            'familia.leer' => 'unidad_familiar',
            'vinculo_tutor.leer' => 'unidad_familiar',
            'consentimiento_imagen.leer' => 'unidad_familiar',
            'consentimiento_imagen.actualizar' => 'unidad_familiar',
        ]);
    }

    /** `POST` de una decisión del propio tutor (canal `portal`). */
    public static function decideAs(object $tenant, User $guardianUser, Person $student, string $level, string $decision): TestResponse
    {
        return self::decide($tenant, $guardianUser, ['student_person' => $student->public_id, 'level' => $level, 'decision' => $decision]);
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
