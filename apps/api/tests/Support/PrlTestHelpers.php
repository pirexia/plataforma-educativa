<?php

namespace Tests\Support;

use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStatus;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FamUnitTestHelpers as Fam;

/**
 * REQ-PRL (1.14b-b): ayudantes de los tests HTTP de la puerta. Se apoyan en `FamUnitTestHelpers` (la familia de recogida, el
 * tercero con fotografía) y añaden el módulo `prl` contratado y los usuarios de conserjería y dirección con los roles
 * predefinidos del centro (la siembra real de `ProvisionTenantDefaults`, `permisos.md §8.4`).
 */
final class PrlTestHelpers
{
    /**
     * Una familia de recogida con `prl` contratado, un abuelo autorizado **con fotografía** (base `tercero`), dos tutores **sin
     * fotografía** (el principal, madre, y el otro, padre), un conserje y un usuario de dirección. El reloj queda congelado.
     *
     * @return array{tenant: Tenant, admin: User, unit: string, student: Person, principal: User, gp: string, lp: string, other: User, go: string, lo: string, text: ?string, abuelo: array{person: string, authorization: string}, conserje: User, direccion: User}
     */
    public static function doorFamily(string $slug): array
    {
        Fam::freeze('2026-11-02 09:00:00');
        $f = Fam::pickupFamily($slug);
        // Sin sesión: `created_by` de la suscripción se toma del usuario autenticado, que la conexión de plataforma no ve.
        resetSessionState();
        contractModule($f['tenant'], 'prl');
        $f['abuelo'] = Fam::thirdParty($f, 1, given: 'Abuelo');
        $f['conserje'] = self::roleUser($f['tenant'], 'conserjeria_pas');
        $f['direccion'] = self::roleUser($f['tenant'], 'direccion');

        return $f;
    }

    /** Usuario activo con un rol predefinido del centro (la siembra real, no un rol de prueba). */
    public static function roleUser(object $tenant, string $roleCode): User
    {
        resetSessionState();

        return app(TenantContext::class)->runFor($tenant->id, function () use ($roleCode): User {
            $user = User::factory()->create();
            $user->status = UserStatus::Activo;
            $user->save();
            $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail()->id);

            return $user;
        });
    }

    /** Descontrata `prl` del centro y olvida la caché del módulo. */
    public static function disableModule(object $tenant): void
    {
        app(TenantContext::class)->runFor($tenant->id, function (): void {
            DB::connection('pgsql_platform')->table('module_subscriptions')->where('module_code', 'prl')->update(['enabled' => false]);
            Cache::forget('modules:prl:enabled');
        });
    }

    /**
     * Llamada autenticada con cabeceras (`$method` en minúsculas).
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public static function call(object $tenant, User $actor, string $method, string $path, array $body = [], array $headers = []): TestResponse
    {
        resetSessionState();

        return test()->actingAs($actor)->json(strtoupper($method), Fam::url($tenant, $path), $body, $headers);
    }

    /**
     * Cuerpo de una salida con la persona que recoge; `$overrides` lo sobrescribe.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function exitBody(array $f, ?string $personId = null, array $overrides = []): array
    {
        return $overrides + [
            'student_person' => $f['student']->public_id,
            'direction' => 'salida',
            'picked_up_by' => $personId ?? $f['abuelo']['person'],
            'verification_method' => 'foto',
        ];
    }

    /** Registra una salida como conserje. */
    public static function exit(array $f, ?string $personId = null, array $overrides = [], ?User $actor = null, array $headers = []): TestResponse
    {
        return self::call($f['tenant'], $actor ?? $f['conserje'], 'post', '/student-movements', self::exitBody($f, $personId, $overrides), $headers);
    }

    /** `public_id` de la persona de un tutor (el tutor principal o el otro) a partir de su usuario. */
    public static function personOf(object $tenant, User $user): string
    {
        return app(TenantContext::class)->runFor($tenant->id, fn () => (string) DB::table('people')->where('id', $user->person_id)->value('public_id'));
    }

    /** Filas de una tabla de `prl` en el contexto del centro. */
    public static function count(object $tenant, string $table): int
    {
        return app(TenantContext::class)->runFor($tenant->id, fn () => DB::table($table)->count());
    }

    /** Código del primer error de un campo de una respuesta `422`/`409`. */
    public static function errorCode(TestResponse $response, string $field): ?string
    {
        return $response->json('errors')[$field][0]['code'] ?? null;
    }
}
