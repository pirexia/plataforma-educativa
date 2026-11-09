<?php

namespace Tests\Support;

use App\Models\Person;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * REQ-ALUM (1.15-a): ayudantes de los tests HTTP del módulo. Métodos estáticos de una clase (y no funciones sueltas)
 * para no repetir nombres entre ficheros (CA-060-08).
 */
final class AlumTestHelpers
{
    /**
     * Centro aprovisionado con `fam_unit` y `alum` contratados y su administrador (que recibe los permisos de ambos
     * módulos por la siembra de `ProvisionTenantDefaults`).
     *
     * @return array{0: Tenant, 1: User}
     */
    public static function setup(string $slug): array
    {
        resetSessionState();

        [$tenant, $admin] = provisionCoreTenant($slug);
        contractModule($tenant, 'fam_unit');
        contractModule($tenant, 'alum');

        return [$tenant, $admin];
    }

    /** Descontrata `alum` del centro (`module_subscriptions` solo la escribe la plataforma) y olvida la caché. */
    public static function disableModule(object $tenant): void
    {
        app(TenantContext::class)->runFor($tenant->id, function (): void {
            DB::connection('pgsql_platform')->table('module_subscriptions')->where('module_code', 'alum')->update(['enabled' => false]);
            Cache::forget('modules:alum:enabled');
        });
    }

    /** Llamada autenticada (`$method` en minúsculas: get, post, patch, delete). */
    public static function call(object $tenant, User $actor, string $method, string $path, array $body = [], array $headers = []): TestResponse
    {
        resetSessionState();

        return test()->actingAs($actor)->json(strtoupper($method), coreApiUrl($tenant->slug, $path), $body, $headers);
    }

    /** Cuerpo válido de alta; `$overrides` lo completa o lo sustituye. */
    public static function body(array $overrides = []): array
    {
        return $overrides + [
            'given_name' => 'Lucía',
            'family_name_1' => 'Ejemplo',
            'birth_date' => '2015-04-12',
        ];
    }

    /** `POST /students` con una `Idempotency-Key` nueva (o la dada). */
    public static function create(object $tenant, User $actor, array $overrides = [], ?string $key = null): TestResponse
    {
        return self::call($tenant, $actor, 'post', '/students', self::body($overrides), ['Idempotency-Key' => $key ?? (string) Str::ulid()]);
    }

    /** Un DNI con letra de control válida a partir de un número. */
    public static function dni(int $number): string
    {
        return FamUnitTestHelpers::dni($number);
    }

    /**
     * Usuario activo con un rol propio que concede exactamente esos permisos, con ámbito `todos`.
     *
     * @param  list<string>  $codes
     */
    public static function userWith(object $tenant, User $admin, string $local, array $codes): User
    {
        return AcadApiHelpers::userWith($tenant, $admin, $local, $codes);
    }

    /**
     * Como `userWith`, con el ámbito de cada permiso (`código => ámbito`).
     *
     * @param  array<string, string>  $grants
     */
    public static function userWithScopes(object $tenant, User $admin, string $local, array $grants): User
    {
        return AcadApiHelpers::userWithScopes($tenant, $admin, $local, $grants);
    }

    /** Entradas de `audit_logs` de un tipo auditable, en el contexto del centro. */
    public static function audit(object $tenant, string $type, ?string $event = null): array
    {
        return FamUnitTestHelpers::audit($tenant, $type, $event);
    }

    /** Fila de una tabla por `public_id`, leída dentro del contexto del centro (RLS). */
    public static function row(object $tenant, string $table, string $publicId): ?object
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): ?object => DB::table($table)->where('public_id', $publicId)->first());
    }

    /** Número de filas de una tabla en el contexto del centro (incluye las dadas de baja). */
    public static function count(object $tenant, string $table): int
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): int => DB::table($table)->count());
    }

    /** `id` interno de una persona por su `public_id`. */
    public static function personId(object $tenant, string $personPublicId): int
    {
        return (int) app(TenantContext::class)->runFor($tenant->id, fn () => DB::table('people')->where('public_id', $personPublicId)->value('id'));
    }

    /** El modelo de una persona por su `public_id` (para los ayudantes de `fam_unit`, que piden el modelo). */
    public static function person(object $tenant, string $personPublicId): Person
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): Person => Person::query()->where('public_id', $personPublicId)->firstOrFail());
    }

    public static function errorCode(TestResponse $response, string $field): ?string
    {
        return $response->json('errors')[$field][0]['code'] ?? null;
    }

    /** Cuántos registros de auditoría de ese tipo y evento hay (todos los eventos si es `null`). */
    public static function auditCount(object $tenant, string $type, ?string $event = null): int
    {
        return count(self::audit($tenant, $type, $event));
    }

    /** Alta mínima por la API; devuelve la ficha. */
    public static function student(object $tenant, User $admin, array $overrides = []): array
    {
        return self::create($tenant, $admin, $overrides)->assertCreated()->json();
    }

    /** Tipos de auditoría usados (alias del morph map). */
    public const TYPE_STUDENT = 'alum_student';
}
