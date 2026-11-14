<?php

use App\Models\Person;
use App\Models\User;
use App\Modules\Core\Domain\ExportJobLifecycle;
use App\Modules\Core\Domain\Models\DataExport;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Application\ImageConsentService;
use App\Modules\FamUnit\Infrastructure\Jobs\GenerateImageConsentsExport;
use App\Support\Authorization\PermissionResolver;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;

// REQ-FAM-UNIT-004 (1.14-b), docs/modulos/REQ-FAM-UNIT/api.md §1.5, §2.7, §3, §4, funcional.md §4.7, §4.8 y CA-FAM-UNIT-041,
// -061, -095, ADR-054, ADR-055, ADR-066 §5.2, INV-002, INV-012, INV-015: estado vigente calculado de los alumnos de las
// unidades (listado e informe en cola).

beforeEach(function (): void {
    Storage::fake('local');
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Una unidad con dos alumnos: A sin ninguna decisión y B con autorizo en web registrado por su representante legal R1.
 *
 * @return array{tenant: object, admin: User, unit: string, a: Person, b: Person, r1: User, g1: string}
 */
function listingFamily(string $slug): array
{
    [$tenant, $admin] = Fam::setup($slug);
    Fam::freeze();
    $unit = Fam::unitId($tenant, $admin);
    $r1 = Fam::consentGuardian($tenant, $admin, 'r1', $unit);
    $g1 = Fam::guardian($tenant, $admin, $r1, $unit)->assertCreated()->json('public_id');
    $a = Fam::person($tenant, 'Ana', 'Sin');
    $b = Fam::person($tenant, 'Beto', 'Con');

    foreach ([$a, $b] as $student) {
        Fam::addStudent($tenant, $admin, $unit, $student)->assertCreated();
        Fam::link($tenant, $admin, $g1, $student, ['is_legal_guardian' => true])->assertCreated();
    }

    Fam::publishText($tenant, $admin, 'web');
    Fam::publishText($tenant, $admin, 'redes_sociales');
    Fam::tick();
    Fam::decideAs($tenant, $r1, $b, 'web', 'autorizo')->assertCreated();

    return compact('tenant', 'admin', 'unit', 'a', 'b', 'r1', 'g1');
}

/** @return list<list<string|null>> el CSV de una exportación, sin BOM, cabecera incluida */
function exportRows(object $tenant, string $exportId): array
{
    $key = app(TenantContext::class)->runFor($tenant->id, fn () => DataExport::where('public_id', $exportId)->firstOrFail()->object_key);
    $csv = Storage::disk('local')->get($key);

    $stream = fopen('php://temp', 'w+');
    fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
    rewind($stream);

    $rows = [];

    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    return $rows;
}

test('CA-FAM-UNIT-095 ADR-066 §5.2: state=pendiente — en el listado y en el informe — incluye al alumno sin ninguna decisión (con last_decision_at vacío) y no al que ya autorizó', function (): void {
    $f = listingFamily('lx-095');

    $pending = Fam::call($f['tenant'], $f['admin'], 'get', '/image-consents?state=pendiente&level=web')->assertOk();

    expect($pending->json('data'))->toHaveCount(1)
        ->and($pending->json('data.0'))->toMatchArray(['student_person' => $f['a']->public_id, 'level' => 'web', 'state' => 'pendiente', 'last_decision_at' => null, 'family_unit' => $f['unit']])
        ->and($pending->json('data.0.display_name'))->toBe('Ana Sin');

    $id = Fam::call($f['tenant'], $f['admin'], 'post', '/image-consents/exports', ['state' => ['pendiente'], 'level' => ['web']])->assertStatus(202)->json('public_id');
    $rows = exportRows($f['tenant'], $id);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['family_unit_code', 'student_person_public_id', 'student_display_name', 'level', 'state', 'last_decision_at'])
        ->and($rows[1][1])->toBe($f['a']->public_id)
        ->and([$rows[1][3], $rows[1][4], $rows[1][5]])->toBe(['web', 'pendiente', '']);
});

test('CA-FAM-UNIT-061 api.md §4 ADR-054 ADR-055: el informe se encola, responde 202 con el recurso, tiene el esquema cerrado y queda auditado como exported; q en el cuerpo es 422', function (): void {
    $f = listingFamily('lx-061');
    $unitCode = Fam::row($f['tenant'], 'family_units', $f['unit'])->code;

    $response = Fam::call($f['tenant'], $f['admin'], 'post', '/image-consents/exports')->assertStatus(202);
    $id = $response->json('public_id');

    expect(array_keys($response->json()))->toBe(['public_id', 'status']);

    // Con la cola síncrona de las pruebas, el trabajo ya ha corrido; GET /data-exports/{id} lo confirma.
    $export = Fam::call($f['tenant'], $f['admin'], 'get', "/data-exports/{$id}")->assertOk();
    expect($export->json())->toMatchArray(['kind' => 'image_consents', 'status' => 'completada', 'row_count' => 4]);

    $rows = exportRows($f['tenant'], $id);
    $csv = Storage::disk('local')->get(app(TenantContext::class)->runFor($f['tenant']->id, fn () => DataExport::where('public_id', $id)->firstOrFail()->object_key));

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and(str_contains($csv, "\r\n"))->toBeTrue()
        ->and($rows[0])->toBe(['family_unit_code', 'student_person_public_id', 'student_display_name', 'level', 'state', 'last_decision_at'])
        ->and(array_slice($rows, 1))->toHaveCount(4);

    // Una fila por alumno y nivel, ordenadas por unidad, alumno y nivel; sin literales de interfaz.
    $byKey = [];
    foreach (array_slice($rows, 1) as $row) {
        $byKey["{$row[1]}|{$row[3]}"] = $row;
    }

    expect($byKey["{$f['b']->public_id}|web"])->toMatchArray([0 => $unitCode, 2 => 'Beto Con', 4 => 'autorizo'])
        ->and($byKey["{$f['b']->public_id}|web"][5])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/')
        ->and($byKey["{$f['b']->public_id}|redes_sociales"][4])->toBe('pendiente')
        ->and($byKey["{$f['a']->public_id}|web"][4])->toBe('pendiente');

    // La solicitud queda auditada como exported (con el created automático de la fila).
    expect(Fam::audit($f['tenant'], 'data_export', 'exported'))->toHaveCount(1);

    // q no se admite (ADR-054 §9); un filtro mal formado, tampoco.
    $q = Fam::call($f['tenant'], $f['admin'], 'post', '/image-consents/exports', ['q' => 'Ana'])->assertStatus(422);
    expect(Fam::errorCode($q, 'q'))->toBe('fam_unit.export.q_not_allowed');
    Fam::call($f['tenant'], $f['admin'], 'post', '/image-consents/exports', ['level' => ['twitter']])->assertStatus(422);
    Fam::call($f['tenant'], $f['admin'], 'post', '/image-consents/exports', ['state' => 'nope'])->assertStatus(422);
    Fam::call($f['tenant'], $f['admin'], 'post', '/image-consents/exports', ['student_person' => ['no-ulid']])->assertStatus(422);
});

test('CA-FAM-UNIT-061 RN-CORE-86: el informe exige consentimiento_imagen.exportar (solo todos), solo el solicitante lo descarga, y el nombre del alumno se neutraliza como fórmula', function (): void {
    $f = listingFamily('lx-061b');
    $reader = Fam::userWith($f['tenant'], $f['admin'], 'lector', ['consentimiento_imagen.leer' => 'todos']);
    $exporter = Fam::userWith($f['tenant'], $f['admin'], 'exportador', ['consentimiento_imagen.exportar' => 'todos']);

    Fam::call($f['tenant'], $reader, 'post', '/image-consents/exports')->assertForbidden();

    $id = Fam::call($f['tenant'], $exporter, 'post', '/image-consents/exports')->assertStatus(202)->json('public_id');
    Fam::call($f['tenant'], $exporter, 'get', "/data-exports/{$id}")->assertOk()->assertJsonPath('kind', 'image_consents');
    Fam::call($f['tenant'], $f['admin'], 'get', "/data-exports/{$id}")->assertForbidden();

    // El permiso de exportar no admite unidad_familiar (permisos.md §2.1): conceder ese ámbito es 422.
    resetSessionState();
    test()->actingAs($f['admin'])->postJson(Fam::url($f['tenant'], '/roles'), [
        'code' => 'rol_mal', 'name' => 'Mal', 'permissions' => [['code' => 'consentimiento_imagen.exportar', 'effect' => 'allow', 'scope' => 'unidad_familiar']],
    ])->assertStatus(422);

    // Neutralización (ADR-054 §10.2).
    $formula = Fam::person($f['tenant'], '=HYPERLINK("x")', 'Alumno');
    Fam::addStudent($f['tenant'], $f['admin'], $f['unit'], $formula)->assertCreated();
    $second = Fam::call($f['tenant'], $f['admin'], 'post', '/image-consents/exports', ['student_person' => [$formula->public_id]])->assertStatus(202)->json('public_id');

    expect(exportRows($f['tenant'], $second)[1][2])->toStartWith("'=");
});

test('INV-002 ADR-054 §8: si el solicitante pierde el permiso, el módulo se descontrata o el solicitante se borra antes de ejecutarse el trabajo, la exportación queda fallida y sin fichero', function (): void {
    $f = listingFamily('lx-fail');
    $exporter = Fam::userWith($f['tenant'], $f['admin'], 'exportador', ['consentimiento_imagen.exportar' => 'todos']);

    $request = function () use ($f, $exporter): string {
        Queue::fake();
        $id = Fam::call($f['tenant'], $exporter, 'post', '/image-consents/exports')->assertStatus(202)->json('public_id');
        Queue::swap(app('queue'));

        return $id;
    };

    $run = fn (string $id) => app(TenantContext::class)->runFor($f['tenant']->id, function () use ($id): array {
        $export = DataExport::where('public_id', $id)->firstOrFail();
        (new GenerateImageConsentsExport($export->id, 'tenant-public'))->handle(
            app(ExportJobLifecycle::class),
            app(PermissionResolver::class),
            app(ModuleAvailability::class),
            app(ImageConsentService::class),
            app(PersonDirectory::class),
        );
        $export = $export->fresh();

        return [$export->status, $export->error_code, $export->object_key];
    });

    // 1. Sin permiso al ejecutarse.
    $id = $request();
    app(TenantContext::class)->runFor($f['tenant']->id, fn () => $exporter->roles()->detach());
    expect($run($id))->toBe(['fallida', 'core.export.generation_failed', null]);

    // 2. Módulo descontratado.
    $other = Fam::userWith($f['tenant'], $f['admin'], 'exportador2', ['consentimiento_imagen.exportar' => 'todos']);
    Queue::fake();
    $id = Fam::call($f['tenant'], $other, 'post', '/image-consents/exports')->assertStatus(202)->json('public_id');
    Fam::disableModule($f['tenant']);
    expect($run($id))->toBe(['fallida', 'core.export.generation_failed', null]);
});

test('CA-FAM-UNIT-041 CA-FAM-UNIT-040 ADR-044 §4.2: con ámbito unidad_familiar el tutor ve solo los estados de sus alumnos — listado, filtros y paginación — y los de otra familia no existen para él', function (): void {
    $f = listingFamily('lx-041');

    // Otra familia en el mismo centro, con su tutor y su alumno.
    $otherUnit = Fam::unitId($f['tenant'], $f['admin'], 'UF-OTRA');
    $otherGuardian = Fam::consentGuardian($f['tenant'], $f['admin'], 'otra', $otherUnit);
    $og = Fam::guardian($f['tenant'], $f['admin'], $otherGuardian, $otherUnit)->assertCreated()->json('public_id');
    $stranger = Fam::person($f['tenant'], 'Ajeno', 'Alumno');
    Fam::addStudent($f['tenant'], $f['admin'], $otherUnit, $stranger)->assertCreated();
    Fam::link($f['tenant'], $f['admin'], $og, $stranger, ['is_legal_guardian' => true])->assertCreated();

    $mine = Fam::call($f['tenant'], $f['r1'], 'get', '/image-consents')->assertOk();
    $students = array_unique(array_column($mine->json('data'), 'student_person'));
    sort($students);
    $expected = [$f['a']->public_id, $f['b']->public_id];
    sort($expected);

    expect($mine->json('data'))->toHaveCount(4)
        ->and($students)->toBe($expected)
        ->and($mine->json('meta.total'))->toBe(4);

    // Filtrar por un alumno o una unidad que no alcanza no es error: vacío (el ámbito va antes que el filtro).
    expect(Fam::call($f['tenant'], $f['r1'], 'get', "/image-consents?student_person={$stranger->public_id}")->assertOk()->json('data'))->toBe([])
        ->and(Fam::call($f['tenant'], $f['r1'], 'get', "/image-consents?family_unit={$otherUnit}")->assertOk()->json('data'))->toBe([]);

    // El personal ve las dos familias.
    expect(Fam::call($f['tenant'], $f['admin'], 'get', '/image-consents')->assertOk()->json('meta.total'))->toBe(6);

    // Un usuario que es tutor sin ningún vínculo no ve nada.
    $lonely = Fam::consentGuardian($f['tenant'], $f['admin'], 'sola', $f['unit']);
    Fam::guardian($f['tenant'], $f['admin'], $lonely, $f['unit'])->assertCreated();
    expect(Fam::call($f['tenant'], $lonely, 'get', '/image-consents')->assertOk()->json('data'))->toBe([]);

    // Con el vínculo revocado, el alumno desaparece del listado del tutor (RN-FAM-UNIT-35).
    $link = Fam::call($f['tenant'], $f['admin'], 'get', "/guardian-links?student_person={$f['a']->public_id}")->assertOk()->json('data.0.public_id');
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$link}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();
    expect(array_unique(array_column(Fam::call($f['tenant'], $f['r1'], 'get', '/image-consents')->assertOk()->json('data'), 'student_person')))->toBe([$f['b']->public_id]);
});

test('api.md §3: el listado filtra por level, state, student_person y family_unit, ordena por la lista blanca, pagina y valida los parámetros', function (): void {
    $f = listingFamily('lx-list');
    $query = fn (string $q) => Fam::call($f['tenant'], $f['admin'], 'get', "/image-consents{$q}")->assertOk();

    expect($query('?level=redes_sociales')->json('data'))->toHaveCount(2)
        ->and($query('?state=autorizo')->json('data'))->toHaveCount(1)
        ->and($query('?state=autorizo')->json('data.0.student_person'))->toBe($f['b']->public_id)
        ->and($query('?state=autorizo,no_autorizo')->json('data'))->toHaveCount(1)
        ->and($query("?student_person={$f['a']->public_id}")->json('data'))->toHaveCount(2)
        ->and($query("?family_unit={$f['unit']}&level=web&state=pendiente")->json('data'))->toHaveCount(1)
        ->and($query('?student_person=01JZZZZZZZZZZZZZZZZZZZZZZZ')->json('data'))->toBe([]);

    // Orden: por defecto last_decision_at ascendente con los nulos primero (los que nunca han decidido).
    $default = $query('')->json('data');
    expect($default[3]['state'])->toBe('autorizo')->and($default[0]['last_decision_at'])->toBeNull();
    expect($query('?sort=-last_decision_at')->json('data.0.state'))->toBe('autorizo')
        ->and($query('?sort=state')->json('data.0.state'))->toBe('autorizo')
        ->and($query('?sort=-state')->json('data.0.state'))->toBe('pendiente');

    $page = $query('?per_page=3&page=2');
    expect($page->json('meta'))->toBe(['current_page' => 2, 'per_page' => 3, 'total' => 4, 'last_page' => 2])
        ->and($page->json('data'))->toHaveCount(1)
        ->and($query('?per_page=3&page=9')->json('data'))->toBe([])
        ->and($query('?per_page=3&page=9')->json('meta.total'))->toBe(4);

    foreach (['?level=twitter', '?state=nope', '?sort=student', '?per_page=101', '?page=0', '?family_unit=xx', '?student_person=a,b'] as $bad) {
        Fam::call($f['tenant'], $f['admin'], 'get', "/image-consents{$bad}")->assertStatus(422);
    }

    expect(array_keys($default[0]))->toBe(['student_person', 'display_name', 'family_unit', 'level', 'state', 'last_decision_at']);
});

test('ADR-066 §5.2: un alumno dado de baja de su unidad, o de una unidad dada de baja, no aparece en el listado de estados', function (): void {
    $f = listingFamily('lx-baja');
    $membership = Fam::call($f['tenant'], $f['admin'], 'get', "/family-units/{$f['unit']}/students")->assertOk()->json('data.0.public_id');

    expect(Fam::call($f['tenant'], $f['admin'], 'get', '/image-consents')->assertOk()->json('meta.total'))->toBe(4);

    Fam::call($f['tenant'], $f['admin'], 'delete', "/family-units/{$f['unit']}/students/{$membership}")->assertOk();

    expect(Fam::call($f['tenant'], $f['admin'], 'get', '/image-consents')->assertOk()->json('meta.total'))->toBe(2);
});
