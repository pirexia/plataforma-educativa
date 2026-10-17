<?php

use App\Models\AuditLog;
use App\Modules\Acad\Domain\Models\AcademicPeriod;
use App\Modules\Acad\Infrastructure\PeriodsOutsideAcademicYearCheck;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Infrastructure\TenantSettingsCache;
use App\Modules\Curso\Domain\AcademicYearDateChangeRegistry;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;
use Tests\Support\AcadPeriodHelpers as Periods;
use Tests\Support\CursoTestHelpers;

// REQ-ACAD-001 (1.11d-a), `RN-ACAD-62`, `OPEN-ACAD-24` = B: la validación real
// de `acad` registrada en `curso`. CA-ACAD-102, y los criterios de REQ-CURSO
// que ejercen esa validación real: CA-CURSO-113 y la segunda parte de
// CA-CURSO-114 (`funcional.md §13.7` de REQ-CURSO). PostgreSQL real
// (ADR-033 §10). La concurrencia (CA-CURSO-115, -120, -122) vive en
// `tests/Concurrency/AcadConcurrencyTest.php`.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

function dateChangePatchUrl(object $tenant, AcademicYear $year): string
{
    return Api::url($tenant, "/academic-years/{$year->public_id}");
}

/** @return array{string, string} */
function acadYearDates(object $tenant, AcademicYear $year): array
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($year): array {
        $fresh = AcademicYear::query()->findOrFail($year->id);

        return [$fresh->starts_on->toDateString(), $fresh->ends_on->toDateString()];
    });
}

/** Curso de prueba en planificación (2026-09-01 a 2027-08-31) con dos etapas y un período vivo en cada una. */
function dateChangeFixture(string $slug): array
{
    [$tenant, $admin, $year] = Api::setup($slug, AcademicYearStatus::Planificacion, '2027-08-31');
    $first = Periods::level($tenant, $year, 'ESO');
    $second = Periods::level($tenant, $year, 'BACH');
    Periods::period($tenant, $year, $first, '2026-09-08', '2026-12-22');
    Periods::period($tenant, $year, $second, '2027-04-06', '2027-06-20');

    return [$tenant, $admin, $year, $first, $second];
}

test('CA-ACAD-102 RN-ACAD-62 REQ-CURSO RN-CURSO-53: un PATCH de fechas que deja fuera períodos vivos da 409 con exactamente una entrada de acad, params exactos sin datos de períodos, nada cambia y no se audita; el usuario no necesita ningún permiso de acad', function (): void {
    [$tenant, $admin, $year] = dateChangeFixture('acd-102');
    $user = Api::userWith($tenant, $admin, 'solo_curso', ['curso_academico.actualizar']);
    $auditBefore = app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->count());

    resetSessionState();
    $response = test()->actingAs($user)->patchJson(dateChangePatchUrl($tenant, $year), ['starts_on' => '2026-10-01', 'ends_on' => '2027-06-01'])->assertStatus(409);

    expect($response->json('type'))->toBe('urn:pge:error:conflict')
        ->and($response->json('errors.date_change'))->toHaveCount(1)
        ->and($response->json('errors.date_change.0.code'))->toBe('acad.conflict.periods_outside_academic_year')
        ->and($response->json('errors.date_change.0.params'))->toBe(['count' => 2, 'periods_starts_on' => '2026-09-08', 'periods_ends_on' => '2027-06-20'])
        ->and($response->json('errors.date_change.0.message'))->toContain('2026-09-08')->toContain('2027-06-20')
        ->and(array_keys($response->json('errors.date_change.0')))->toBe(['code', 'message', 'params'])
        ->and(acadYearDates($tenant, $year))->toBe(['2026-09-01', '2027-08-31'])
        ->and(app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->count()))->toBe($auditBefore)
        ->and(Periods::count($tenant))->toBe(2);

    // Solo el inicio: un período queda fuera.
    resetSessionState();
    $one = test()->actingAs($user)->patchJson(dateChangePatchUrl($tenant, $year), ['starts_on' => '2026-09-10'])->assertStatus(409);

    expect($one->json('errors.date_change.0.params'))->toBe(['count' => 1, 'periods_starts_on' => '2026-09-08', 'periods_ends_on' => '2027-06-20']);
});

test('CA-ACAD-102 RN-ACAD-62 ADR-038 §6.3: el message de la entrada sale en el idioma de la petición (es-ES, en, de, fr) con las dos fechas', function (string $locale, string $fragment): void {
    [$tenant, $admin, $year] = dateChangeFixture('acd-102-'.strtolower($locale));

    // El idioma de la persona (es-ES) gana al de la cabecera si está activo: se deja activo solo el pedido.
    app(TenantContext::class)->runFor($tenant->id, function () use ($locale): void {
        TenantSetting::query()->update(['active_locales' => [$locale], 'default_locale' => $locale]);
        app(TenantSettingsCache::class)->forget();
    });

    resetSessionState();
    $response = test()->withHeader('Accept-Language', $locale)->actingAs($admin)
        ->patchJson(dateChangePatchUrl($tenant, $year), ['starts_on' => '2026-10-01'])->assertStatus(409);

    expect($response->json('errors.date_change.0.message'))->toContain($fragment)->toContain('2026-09-08')->toContain('2027-06-20')
        ->and($response->json('errors.date_change.0.code'))->toBe('acad.conflict.periods_outside_academic_year');
})->with([
    'es-ES' => ['es-ES', 'quedarían fuera del curso académico'],
    'en' => ['en', 'would fall outside the academic year'],
    'de' => ['de', 'außerhalb des Schuljahres'],
    'fr' => ['fr', 'en dehors de l’année scolaire'],
]);

test('CA-ACAD-102 RN-ACAD-62 AR-12: la aplicación arrancada registra exactamente una validación de acad y la clave conflict.periods_outside_academic_year existe en los cuatro idiomas', function (): void {
    $acad = array_values(array_filter(app(AcademicYearDateChangeRegistry::class)->all(), fn ($check): bool => $check->moduleCode() === 'acad'));

    expect($acad)->toHaveCount(1)
        ->and($acad[0])->toBeInstanceOf(PeriodsOutsideAcademicYearCheck::class);

    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        $messages = require lang_path("{$locale}/acad.php");

        expect($messages['conflict'])->toHaveKey('periods_outside_academic_year');
    }
});

test('CA-CURSO-113 RN-ACAD-62 RN-ACAD-57: con la validación real de acad, un PATCH que deja fuera un período vivo es 409 (inicio o fin); los bordes de los períodos, incluidos, son 200; un período borrado no cuenta', function (): void {
    [$tenant, $admin, $year, $first] = dateChangeFixture('cur-113');
    // Un período borrado que quedaría fuera de cualquier ajuste.
    Periods::period($tenant, $year, $first, '2026-09-01', '2026-09-05', deleted: true);

    resetSessionState();
    $starts = test()->actingAs($admin)->patchJson(dateChangePatchUrl($tenant, $year), ['starts_on' => '2026-09-10'])->assertStatus(409);

    expect($starts->json('errors.date_change.0.code'))->toBe('acad.conflict.periods_outside_academic_year')
        ->and($starts->json('errors.date_change.0.params'))->toBe(['count' => 1, 'periods_starts_on' => '2026-09-08', 'periods_ends_on' => '2027-06-20']);

    resetSessionState();
    test()->actingAs($admin)->patchJson(dateChangePatchUrl($tenant, $year), ['ends_on' => '2027-06-19'])->assertStatus(409)
        ->assertJsonPath('errors.date_change.0.code', 'acad.conflict.periods_outside_academic_year');

    expect(acadYearDates($tenant, $year))->toBe(['2026-09-01', '2027-08-31']);

    // Los bordes de los períodos, incluidos: 200.
    resetSessionState();
    test()->actingAs($admin)->patchJson(dateChangePatchUrl($tenant, $year), ['starts_on' => '2026-09-08', 'ends_on' => '2027-06-20'])->assertOk();

    expect(acadYearDates($tenant, $year))->toBe(['2026-09-08', '2027-06-20']);

    // El mismo curso, ampliado de nuevo: 200 (los períodos siguen dentro).
    resetSessionState();
    test()->actingAs($admin)->patchJson(dateChangePatchUrl($tenant, $year), ['starts_on' => '2026-09-01', 'ends_on' => '2027-08-31'])->assertOk();

    // Un PATCH que solo cambia code no ejecuta la validación (RN-CURSO-50) aunque el curso tuviera períodos fuera.
    resetSessionState();
    test()->actingAs($admin)->patchJson(dateChangePatchUrl($tenant, $year), ['code' => '2026-2027-b'])->assertOk();
});

test('CA-CURSO-113 RN-ACAD-62 RN-CURSO-52 OPEN-CURSO-26: con acad descontratado en el centro, el PATCH que dejaría fuera períodos vivos responde 200', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-113d');
    $year = CursoTestHelpers::year($tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-09-01', '2027-08-31');
    $level = Periods::level($tenant, $year);
    Periods::period($tenant, $year, $level, '2026-09-08', '2026-12-22');

    resetSessionState();
    test()->actingAs($admin)->patchJson(dateChangePatchUrl($tenant, $year), ['starts_on' => '2026-10-01'])->assertOk();

    expect(acadYearDates($tenant, $year)[0])->toBe('2026-10-01');

    // Al contratar acad, el mismo tipo de cambio sí se valida.
    resetSessionState();
    contractModule($tenant, 'acad');
    test()->actingAs($admin)->patchJson(dateChangePatchUrl($tenant, $year), ['starts_on' => '2026-11-01'])->assertStatus(409);
});

test('CA-CURSO-114 INV-001 RN-ACAD-62: la validación real de acad solo ve los períodos de su centro; el public_id de un curso de otro centro es 404, nunca 403 ni 409, y ninguna fila cambia', function (): void {
    [$a, $adminA, $yearA] = Api::setup('cur-114a', AcademicYearStatus::Planificacion, '2027-08-31');
    [$b, $adminB, $yearB] = Api::setup('cur-114b', AcademicYearStatus::Planificacion, '2027-08-31');
    $levelB = Periods::level($b, $yearB);
    Periods::period($b, $yearB, $levelB, '2026-09-08', '2026-12-22');

    // El centro A no tiene períodos: el período del centro B (que quedaría fuera) no se ve.
    resetSessionState();
    test()->actingAs($adminA)->patchJson(dateChangePatchUrl($a, $yearA), ['starts_on' => '2026-10-01'])->assertOk();

    expect(acadYearDates($a, $yearA)[0])->toBe('2026-10-01');

    // El curso del otro centro: 404 y nada cambia en ninguno de los dos.
    resetSessionState();
    test()->actingAs($adminA)->patchJson(dateChangePatchUrl($a, $yearB), ['starts_on' => '2026-10-01'])->assertNotFound();

    expect(acadYearDates($b, $yearB))->toBe(['2026-09-01', '2027-08-31'])
        ->and(acadYearDates($a, $yearA))->toBe(['2026-10-01', '2027-08-31'])
        ->and(Periods::count($b))->toBe(1)
        ->and(Periods::count($a))->toBe(0)
        ->and(app(TenantContext::class)->runFor($b->id, fn () => AcademicPeriod::query()->count()))->toBe(1);

    // Y el propio centro B sí queda protegido por su período.
    resetSessionState();
    test()->actingAs($adminB)->patchJson(dateChangePatchUrl($b, $yearB), ['starts_on' => '2026-10-01'])->assertStatus(409);
});
