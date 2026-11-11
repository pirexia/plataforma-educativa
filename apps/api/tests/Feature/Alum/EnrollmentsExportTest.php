<?php

use App\Modules\Alum\Application\EnrollmentService;
use App\Modules\Alum\Infrastructure\Jobs\GenerateEnrollmentsExport;
use App\Modules\Core\Domain\ExportJobLifecycle;
use App\Modules\Core\Domain\Models\DataExport;
use App\Support\Authorization\PermissionResolver;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AlumEnrollmentHelpers as En;
use Tests\Support\AlumTestHelpers as Alum;

// REQ-ALUM-002 (1.15-b T2c), docs/modulos/REQ-ALUM/api.md §6, funcional.md §4.5 y §11.4: exportación de matrículas en cola.
// CA-ALUM-018b, CA-ALUM-041, CA-ALUM-042; RN-ALUM-64; ADR-054, ADR-055; INV-001, INV-002, INV-003, INV-012, INV-015.

beforeEach(function (): void {
    Storage::fake('local');
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Escenario con tres matrículas: Uno en el grupo A, Dos en el grupo B y Tres sin grupo y dado de baja. */
function exportScenario(string $slug): array
{
    $s = En::scenario($slug);

    foreach ([0 => $s['api']['group'], 1 => $s['groupB'], 2 => null] as $i => $group) {
        $id = Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments', En::body($s, $i, ['group' => $group]))->assertCreated()->json('public_id');

        if ($i === 2) {
            Alum::call($s['tenant'], $s['admin'], 'post', "/enrollments/{$id}/withdrawal", ['withdrawn_on' => '2026-10-01', 'withdrawal_reason' => 'traslado'])->assertOk();
            $s['withdrawn'] = $id;
        }
    }

    return $s;
}

/** @return list<list<string|null>> el CSV de una exportación, sin BOM, cabecera incluida */
function enrollmentExportRows(object $tenant, string $exportId): array
{
    $key = app(TenantContext::class)->runFor($tenant->id, fn () => DataExport::where('public_id', $exportId)->firstOrFail()->object_key);
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', Storage::disk('local')->get($key)));
    rewind($stream);

    $rows = [];

    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    return $rows;
}

function exportRequest(array $s, array $body = [], $as = null)
{
    return Alum::call($s['tenant'], $as ?? $s['admin'], 'post', '/enrollments/exports', ['academic_year' => $s['year']->public_id] + $body);
}

test('CA-ALUM-041 api.md §6 ADR-054 ADR-055 INV-012 INV-003: POST /enrollments/exports es 202, se encola, el CSV tiene el esquema cerrado sin documento y la solicitud queda auditada como exported con los filtros', function (): void {
    $s = exportScenario('enrexp-041');

    $response = exportRequest($s)->assertStatus(202);
    $id = $response->json('public_id');

    expect(array_keys($response->json()))->toBe(['public_id', 'status']);

    $export = Alum::call($s['tenant'], $s['admin'], 'get', "/data-exports/{$id}")->assertOk();
    expect($export->json())->toMatchArray(['kind' => 'alum_enrollments', 'status' => 'completada', 'row_count' => 3]);

    $rows = enrollmentExportRows($s['tenant'], $id);

    expect($rows[0])->toBe(['enrollment_public_id', 'student_public_id', 'family_name', 'given_name', 'birth_date', 'academic_year_code', 'course_code', 'group_name', 'status', 'enrolled_on', 'withdrawn_on', 'withdrawal_reason'])
        ->and(array_slice($rows, 1))->toHaveCount(3);

    $byStudent = [];
    foreach (array_slice($rows, 1) as $row) {
        $byStudent[$row[1]] = $row;
    }

    $one = $byStudent[$s['students'][0]['public_id']];
    $three = $byStudent[$s['students'][2]['public_id']];

    expect($one[2])->toBe('Uno')
        ->and($one[3])->toBe('Alumno')
        ->and($one[4])->toBe('2015-04-12')
        ->and(array_slice($one, 5))->toBe([$s['year']->code, '1ESO', 'Grupo A', 'activa', '2026-09-10', '', ''])
        ->and($byStudent[$s['students'][1]['public_id']][7])->toBe('Grupo B')
        ->and($three[7])->toBe('')
        ->and(array_slice($three, 8))->toBe(['baja', '2026-09-10', '2026-10-01', 'traslado']);

    // Sin documento de identidad en ninguna celda (minimización, api.md §6).
    expect(json_encode($rows))->not->toContain('dni');

    // `exported` más el `created` automático de la fila, que lleva los filtros (ADR-035 §4): el rastro de qué se pidió.
    expect(Alum::audit($s['tenant'], 'data_export', 'exported'))->toHaveCount(1);

    $created = Alum::audit($s['tenant'], 'data_export', 'created');
    expect($created)->toHaveCount(1)
        ->and(json_encode($created[0]->changes))->toContain($s['year']->public_id)->toContain('alum_enrollments');

    // Validación síncrona (INV-010): q, estado, tipo, curso académico ausente o inexistente.
    $q = exportRequest($s, ['q' => 'Uno'])->assertUnprocessable();
    expect(Alum::errorCode($q, 'q'))->toBe('alum.validation.export_query_not_allowed');
    expect(Alum::errorCode(exportRequest($s, ['status' => 'otra'])->assertUnprocessable(), 'status'))->toBe('alum.validation.status_invalid');
    expect(Alum::errorCode(exportRequest($s, ['group' => ['x']])->assertUnprocessable(), 'group'))->toBe('alum.validation.text_invalid');
    expect(Alum::errorCode(Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments/exports', [])->assertUnprocessable(), 'academic_year'))->toBe('alum.validation.required');
    Alum::call($s['tenant'], $s['admin'], 'post', '/enrollments/exports', ['academic_year' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])->assertNotFound();
});

test('CA-ALUM-042 RN-ALUM-64 RN-CORE-86: sin matricula.exportar es 403 (también con matricula.leer de ámbito grupo); la exportación con filtro group tiene exactamente las filas del listado y solo el solicitante la descarga', function (): void {
    $s = exportScenario('enrexp-042');
    $reader = Alum::userWithScopes($s['tenant'], $s['admin'], 'docente', ['matricula.leer' => 'grupo']);
    $exporter = Alum::userWithScopes($s['tenant'], $s['admin'], 'exportador', ['matricula.exportar' => 'todos']);

    exportRequest($s, [], $reader)->assertForbidden();
    expect(app(TenantContext::class)->runFor($s['tenant']->id, fn () => DataExport::query()->count()))->toBe(0);

    $id = exportRequest($s, ['group' => $s['api']['group']], $exporter)->assertStatus(202)->json('public_id');
    Alum::call($s['tenant'], $exporter, 'get', "/data-exports/{$id}")->assertOk()->assertJsonPath('kind', 'alum_enrollments');
    Alum::call($s['tenant'], $s['admin'], 'get', "/data-exports/{$id}")->assertForbidden();

    $listed = Alum::call($s['tenant'], $s['admin'], 'get', "/enrollments?academic_year={$s['year']->public_id}&group={$s['api']['group']}")->assertOk()->json('data');
    $exported = array_column(array_slice(enrollmentExportRows($s['tenant'], $id), 1), 0);

    expect($exported)->toEqualCanonicalizing(array_column($listed, 'public_id'))->toHaveCount(1);

    // El mismo filtro `status` del listado: solo las bajas.
    $bajas = exportRequest($s, ['status' => 'baja'])->assertStatus(202)->json('public_id');
    expect(array_column(array_slice(enrollmentExportRows($s['tenant'], $bajas), 1), 0))->toBe([$s['withdrawn']]);
});

test('INV-002 ADR-054 §8: si el solicitante pierde el permiso o el módulo se descontrata antes de ejecutarse el trabajo, la exportación queda fallida y sin fichero', function (): void {
    $s = exportScenario('enrexp-fail');
    $exporter = Alum::userWithScopes($s['tenant'], $s['admin'], 'exportador', ['matricula.exportar' => 'todos']);

    $run = fn (string $id) => app(TenantContext::class)->runFor($s['tenant']->id, function () use ($id): array {
        $export = DataExport::where('public_id', $id)->firstOrFail();
        (new GenerateEnrollmentsExport($export->id, 'tenant-public'))->handle(
            app(ExportJobLifecycle::class),
            app(PermissionResolver::class),
            app(ModuleAvailability::class),
            app(EnrollmentService::class),
        );
        $export = $export->fresh();

        return [$export->status, $export->error_code, $export->object_key];
    });

    Queue::fake();
    $id = exportRequest($s, [], $exporter)->assertStatus(202)->json('public_id');
    app(TenantContext::class)->runFor($s['tenant']->id, fn () => $exporter->roles()->detach());
    expect($run($id))->toBe(['fallida', 'core.export.generation_failed', null]);

    $other = Alum::userWithScopes($s['tenant'], $s['admin'], 'exportador2', ['matricula.exportar' => 'todos']);
    $id = exportRequest($s, [], $other)->assertStatus(202)->json('public_id');
    Alum::disableModule($s['tenant']);
    expect($run($id))->toBe(['fallida', 'core.export.generation_failed', null]);
});

test('CA-ALUM-041 INV-012: el trabajo va a la cola default con el tenant en el payload y la petición no genera el CSV', function (): void {
    $s = exportScenario('enrexp-queue');

    Queue::fake();
    $id = exportRequest($s)->assertStatus(202)->json('public_id');

    Queue::assertPushedOn('default', GenerateEnrollmentsExport::class);
    expect(app(TenantContext::class)->runFor($s['tenant']->id, fn () => DataExport::where('public_id', $id)->firstOrFail()->status))->toBe('pendiente');
});

test('CA-ALUM-041 api.md §6: el límite de tasa cuenta todos los intentos por centro y usuario y responde 429 con Retry-After y texto traducido', function (): void {
    $s = exportScenario('enrexp-429');
    config(['alum.export.rate_limit.attempts' => 2]);

    exportRequest($s)->assertStatus(202);
    exportRequest($s, ['q' => 'x'])->assertUnprocessable(); // un intento rechazado también cuenta

    $limited = exportRequest($s)->assertStatus(429);
    expect($limited->headers->get('Retry-After'))->not->toBeNull()
        ->and($limited->json('detail'))->toContain('límite de exportaciones');

    // Otro usuario del mismo centro tiene su propio cubo.
    $other = Alum::userWithScopes($s['tenant'], $s['admin'], 'otro', ['matricula.exportar' => 'todos']);
    exportRequest($s, [], $other)->assertStatus(202);
});

test('CA-ALUM-018b INV-001: con dos centros equivalentes, la exportación del primero solo contiene sus matrículas y un curso académico del segundo es 404', function (): void {
    $a = exportScenario('enrexp-iso-a');
    $b = exportScenario('enrexp-iso-b');

    $id = exportRequest($a)->assertStatus(202)->json('public_id');
    $rows = array_slice(enrollmentExportRows($a['tenant'], $id), 1);

    $ownIds = array_column(Alum::call($a['tenant'], $a['admin'], 'get', "/enrollments?academic_year={$a['year']->public_id}")->assertOk()->json('data'), 'public_id');
    $foreign = array_column(Alum::call($b['tenant'], $b['admin'], 'get', "/enrollments?academic_year={$b['year']->public_id}")->assertOk()->json('data'), 'public_id');

    expect(array_column($rows, 0))->toEqualCanonicalizing($ownIds)
        ->and(array_intersect(array_column($rows, 0), $foreign))->toBe([]);

    Alum::call($a['tenant'], $a['admin'], 'post', '/enrollments/exports', ['academic_year' => $b['year']->public_id])->assertNotFound();

    // La descarga de la exportación de otro centro no existe (RLS): 404.
    Alum::call($b['tenant'], $b['admin'], 'get', "/data-exports/{$id}")->assertNotFound();
});
