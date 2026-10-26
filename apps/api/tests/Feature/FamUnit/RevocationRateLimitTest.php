<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT (1.14-a), issue #470, docs/modulos/REQ-FAM-UNIT/api.md §2.5 y §7, CA-FAM-UNIT-050: límite de revocar y
// levantar la revocación (patrón de `RolloverRateLimit`), por centro y usuario, un cubo por acción; lecturas y demás
// escrituras sin límite.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    // Los contadores viven en la caché (Redis en tests): se vacían para que ningún test herede intentos de otro.
    Cache::flush();
});

/** @return array{tenant: object, admin: User, links: list<string>} */
function rateScenario(string $slug, int $links = 4): array
{
    [$tenant, $admin] = Fam::setup($slug);
    $unit = Fam::unitId($tenant, $admin);
    [, $guardian] = Fam::familyUser($tenant, $admin, 'tut', $unit);
    $ids = [];

    for ($i = 0; $i < $links; $i++) {
        $ids[] = Fam::link($tenant, $admin, $guardian, Fam::person($tenant, 'Alumno', "N{$i}"))->assertCreated()->json('public_id');
    }

    return ['tenant' => $tenant, 'admin' => $admin, 'links' => $ids];
}

test('CA-FAM-UNIT-050 issue #470: superado el límite, revocar es 429 con Retry-After y texto traducido; levantar tiene su propio cubo; usuario y centro distintos no se ven afectados; las lecturas y otras escrituras no se limitan', function (): void {
    config(['fam_unit.revocation.rate_limit.attempts' => 2]);
    $s = rateScenario('rl-050');
    $other = rateScenario('rl-050b', 1);
    $body = ['reason' => 'resolucion_judicial'];

    // Dos intentos (también el 409 de estado cuenta); el tercero es 429.
    Fam::call($s['tenant'], $s['admin'], 'post', "/guardian-links/{$s['links'][0]}/revocation", $body)->assertOk();
    Fam::call($s['tenant'], $s['admin'], 'post', "/guardian-links/{$s['links'][0]}/revocation", $body)->assertStatus(409);
    $limited = Fam::call($s['tenant'], $s['admin'], 'post', "/guardian-links/{$s['links'][1]}/revocation", $body)->assertStatus(429);

    expect($limited->headers->get('Retry-After'))->toBeNumeric()
        ->and($limited->json('type'))->toBe('urn:pge:error:too-many-requests')
        ->and($limited->json('detail'))->toContain('límite de revocaciones');
    // No llegó a revocar nada.
    expect(Fam::row($s['tenant'], 'guardian_student_links', $s['links'][1])->status)->toBe('activo');

    // El cubo de levantar es otro: sus dos intentos funcionan y el tercero es 429.
    Fam::call($s['tenant'], $s['admin'], 'delete', "/guardian-links/{$s['links'][0]}/revocation")->assertOk();
    Fam::call($s['tenant'], $s['admin'], 'delete', "/guardian-links/{$s['links'][0]}/revocation")->assertStatus(409);
    Fam::call($s['tenant'], $s['admin'], 'delete', "/guardian-links/{$s['links'][0]}/revocation")->assertStatus(429);

    // Otro usuario del mismo centro con permiso: su cubo está intacto.
    $second = Fam::userWith($s['tenant'], $s['admin'], 'segundo', ['revocacion_tutor.crear' => 'todos']);
    Fam::call($s['tenant'], $second, 'post', "/guardian-links/{$s['links'][1]}/revocation", $body)->assertOk();

    // Otro centro: no se ve afectado.
    Fam::call($other['tenant'], $other['admin'], 'post', "/guardian-links/{$other['links'][0]}/revocation", $body)->assertOk();

    // Lecturas y demás escrituras, sin límite propio (aunque el cubo de revocar esté agotado).
    for ($i = 0; $i < 6; $i++) {
        Fam::call($s['tenant'], $s['admin'], 'get', '/guardian-links')->assertOk();
    }
    Fam::call($s['tenant'], $s['admin'], 'patch', "/guardian-links/{$s['links'][2]}", ['access_level' => 'lectura_escritura'])->assertOk();
    Fam::unit($s['tenant'], $s['admin'])->assertCreated();

    // El mensaje existe en los cuatro idiomas con el parámetro de minutos.
    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        $lang = require base_path("lang/{$locale}/fam_unit.php");
        expect($lang['errors']['revocation_rate_limited'])->toContain(':minutes');
    }
});

test('CA-FAM-UNIT-050: el límite por defecto es de 30 intentos por hora y cuenta por usuario y centro, no por vínculo', function (): void {
    expect(config('fam_unit.revocation.rate_limit.attempts'))->toBe(30)
        ->and(config('fam_unit.revocation.rate_limit.decay_seconds'))->toBe(3600);
});
