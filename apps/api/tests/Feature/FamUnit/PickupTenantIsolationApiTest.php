<?php

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-005 (1.14b-a), CA-FAM-UNIT-124, INV-001, security-reviewer M3: aislamiento entre dos centros con familias, terceros,
// fotografías y autorizaciones equivalentes. Desde A, ningún endpoint nuevo devuelve, modifica ni cuenta datos de B.

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** @return array<string, mixed> el estado observable de B, para comprobar que no cambia. */
function pickupSnapshot(array $f): array
{
    return app(TenantContext::class)->runFor($f['tenant']->id, fn () => [
        'authorizations' => DB::table('pickup_authorizations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'photos' => DB::table('pickup_photos')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'people' => DB::table('people')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'objects' => Storage::disk('local')->allFiles("tenants/{$f['tenant']->public_id}"),
        'reads' => AuditLog::query()->where('event', 'read')->count(),
    ]);
}

test('CA-FAM-UNIT-124 INV-001: desde A, GET/GET{id}/withdrawal de /pickup-authorizations, GET /pickup-lists/{alumno}, GET/PUT/DELETE de la fotografía y GET /pickup-persons?document_* no alcanzan nada de B ni lo modifican', function (): void {
    Fam::freeze('2026-11-02 09:00:00');
    $a = Fam::pickupFamily('iso-a');
    $b = Fam::pickupFamily('iso-b');
    // El mismo documento en los dos centros: cada uno ve el suyo.
    $thirdA = Fam::thirdParty($a, 77);
    $thirdB = Fam::thirdParty($b, 77);
    $onlyB = Fam::thirdParty($b, 78, given: 'SoloB');
    $before = pickupSnapshot($b);

    // Listado: A ve solo lo suyo y su total no cuenta lo de B.
    $listing = Fam::call($a['tenant'], $a['admin'], 'get', '/pickup-authorizations')->assertOk();
    expect(array_column($listing->json('data'), 'public_id'))->toBe([$thirdA['authorization']])
        ->and($listing->json('meta.total'))->toBe(1)
        ->and(Fam::call($a['tenant'], $a['admin'], 'get', "/pickup-authorizations?person={$onlyB['person']}")->assertOk()->json('data'))->toBe([])
        ->and(Fam::call($a['tenant'], $a['admin'], 'get', "/pickup-authorizations?student_person={$b['student']->public_id}")->assertOk()->json('data'))->toBe([]);

    // Detalle y retirada de una autorización de B: 404, sin cambios.
    Fam::call($a['tenant'], $a['admin'], 'get', "/pickup-authorizations/{$thirdB['authorization']}")->assertNotFound();
    Fam::call($a['tenant'], $a['admin'], 'post', "/pickup-authorizations/{$thirdB['authorization']}/withdrawal")->assertNotFound();
    Fam::call($a['tenant'], $a['admin'], 'patch', "/pickup-authorizations/{$thirdB['authorization']}", ['valid_until' => '2026-11-01'])->assertNotFound();

    // La lista de un alumno de B.
    Fam::call($a['tenant'], $a['admin'], 'get', "/pickup-lists/{$b['student']->public_id}")->assertNotFound();

    // Fotografía de una persona de B: leer, subir, borrar.
    Fam::call($a['tenant'], $a['admin'], 'get', "/pickup-persons/{$onlyB['person']}/photo")->assertNotFound();
    Fam::putPhoto($a['tenant'], $a['admin'], $onlyB['person'])->assertNotFound();
    Fam::call($a['tenant'], $a['admin'], 'delete', "/pickup-persons/{$onlyB['person']}/photo")->assertNotFound();

    // Búsqueda por documento: el documento que solo existe en B no se encuentra desde A; el que existe en los dos devuelve el de A.
    expect(Fam::call($a['tenant'], $a['admin'], 'get', '/pickup-persons?document_type=dni&document_number='.Fam::dni(78))->assertOk()->json('data'))->toBe([])
        ->and(Fam::call($a['tenant'], $a['admin'], 'get', '/pickup-persons?document_type=dni&document_number='.Fam::dni(77))->assertOk()->json('data.0.person'))->toBe($thirdA['person']);

    // Lo de B no cambió: ni filas, ni objetos, ni lecturas auditadas.
    expect(pickupSnapshot($b))->toBe($before);

    // Y simétricamente: la lista de A no incluye a nadie de B.
    expect(Fam::listedPersons($a['tenant'], $a['admin'], $a['student']))->toBe([...Fam::listedPersons($a['tenant'], $a['admin'], $a['student'])])
        ->not->toContain($thirdB['person'])->not->toContain($onlyB['person']);
});
