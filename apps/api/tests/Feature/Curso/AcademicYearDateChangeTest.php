<?php

use App\Models\AuditLog;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Infrastructure\TenantSettingsCache;
use App\Modules\Curso\Domain\AcademicYearClosureCheck;
use App\Modules\Curso\Domain\AcademicYearClosureFailure;
use App\Modules\Curso\Domain\AcademicYearClosureRegistry;
use App\Modules\Curso\Domain\AcademicYearDateChange;
use App\Modules\Curso\Domain\AcademicYearDateChangeCheck;
use App\Modules\Curso\Domain\AcademicYearDateChangeFailure;
use App\Modules\Curso\Domain\AcademicYearDateChangeRegistry;
use App\Modules\Curso\Domain\AcademicYearDirectory;
use App\Modules\Curso\Domain\AcademicYearSharedLock;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\AcademicYearSummary;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\CursoTestHelpers;
use Tests\Support\DateChangeProbeCheck;

// REQ-CURSO-001 (1.11d-a), REQ-ACAD-001, OPEN-ACAD-24: validaciones del
// cambio de fechas (RN-CURSO-49 a -54), lectura con bloqueo compartido
// (RN-CURSO-55 a -59) y filtro por módulo contratado (RN-CURSO-52, -60).
// funcional.md §13.7, CA-CURSO-108 a -118 y -121 (mitad de cierre). PostgreSQL
// real. Los criterios que exigen la validación real de `acad` o dos/tres
// procesos reales viven en `tests/Feature/Acad` y `tests/Concurrency`.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

function dateChangeYear(object $tenant, AcademicYearStatus $status = AcademicYearStatus::Planificacion, string $code = '2026-2027'): AcademicYear
{
    return CursoTestHelpers::year($tenant, $code, $status, '2026-09-01', '2027-08-31');
}

function dateChangeUrl(object $tenant, string $publicId): string
{
    return coreApiUrl($tenant->slug, "/academic-years/{$publicId}");
}

function yearState(object $tenant, AcademicYear $year): array
{
    return app(TenantContext::class)->runFor($tenant->id, function () use ($year): array {
        $fresh = AcademicYear::query()->findOrFail($year->id);

        return [$fresh->starts_on->toDateString(), $fresh->ends_on->toDateString(), $fresh->code, $fresh->status->value];
    });
}

test('CA-CURSO-108 RN-CURSO-49 RN-CURSO-51 RN-CURSO-53 RN-CURSO-54 REQ-ACAD-001 OPEN-ACAD-24: una validación que falla da 409 con su entrada en errors.date_change[], la fila no cambia y no se audita', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-108');
    $year = dateChangeYear($tenant);
    $probe = new DateChangeProbeCheck('curso', 'test.date_change_rejected');
    app(AcademicYearDateChangeRegistry::class)->register($probe);

    $auditBefore = app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->pluck('id')->all());

    test()->actingAs($admin)->patchJson(dateChangeUrl($tenant, $year->public_id), ['ends_on' => '2027-07-31'])
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:pge:error:conflict')
        ->assertJsonPath('errors.date_change.0.code', 'test.date_change_rejected')
        ->assertJsonPath('errors.date_change.0.message', 'Rechazado por la prueba.')
        ->assertJsonPath('errors.date_change.0.params', ['n' => 1])
        ->assertJsonCount(1, 'errors.date_change');

    $created = app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()->where('auditable_type', 'academic_year')->whereNotIn('id', $auditBefore)->get(['event', 'auditable_type', 'auditable_id'])->toArray());

    expect(yearState($tenant, $year))->toBe(['2026-09-01', '2027-08-31', '2026-2027', 'planificacion'])
        ->and($created)->toBe([]);
});

test('CA-CURSO-108 RN-CURSO-53: el detalle del 409 sale traducido en los cuatro idiomas', function (string $locale, string $detail): void {
    [$tenant, $admin] = provisionCoreTenant('cur-108-'.strtolower($locale));
    $year = dateChangeYear($tenant);
    app(AcademicYearDateChangeRegistry::class)->register(new DateChangeProbeCheck('curso', 'test.date_change_rejected'));

    // El idioma de la persona autenticada (es-ES) gana al de la cabecera si está activo
    // en el centro (ADR-038 §11): se deja activo solo el idioma pedido.
    app(TenantContext::class)->runFor($tenant->id, function () use ($locale): void {
        TenantSetting::query()->update(['active_locales' => [$locale], 'default_locale' => $locale]);
        app(TenantSettingsCache::class)->forget();
    });

    test()->withHeader('Accept-Language', $locale)->actingAs($admin)
        ->patchJson(dateChangeUrl($tenant, $year->public_id), ['ends_on' => '2027-07-31'])
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:pge:error:conflict')
        ->assertJsonPath('detail', $detail);
})->with([
    'es-ES' => ['es-ES', 'Las nuevas fechas del curso académico dejan fuera datos que dependen de ellas.'],
    'en' => ['en', 'The new dates of the academic year leave out data that depends on them.'],
    'de' => ['de', 'Die neuen Daten des Schuljahres schließen davon abhängige Daten aus.'],
    'fr' => ['fr', 'Les nouvelles dates de l’année scolaire excluent des données qui en dépendent.'],
]);

test('CA-CURSO-108 RN-CURSO-51: con dos validaciones que fallan, errors.date_change[] tiene dos entradas (no se detiene en la primera)', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-108b');
    $year = dateChangeYear($tenant);
    $first = new DateChangeProbeCheck('curso', 'test.first');
    $second = new DateChangeProbeCheck('curso', 'test.second');
    app(AcademicYearDateChangeRegistry::class)->register($first);
    app(AcademicYearDateChangeRegistry::class)->register($second);

    test()->actingAs($admin)->patchJson(dateChangeUrl($tenant, $year->public_id), ['ends_on' => '2027-07-31'])
        ->assertStatus(409)
        ->assertJsonCount(2, 'errors.date_change')
        ->assertJsonPath('errors.date_change.0.code', 'test.first')
        ->assertJsonPath('errors.date_change.1.code', 'test.second');

    expect($first->received)->toHaveCount(1)->and($second->received)->toHaveCount(1);
});

test('CA-CURSO-109 RN-CURSO-49 RN-CURSO-50: recibe el resumen anterior y las fechas resultantes; solo se ejecuta si alguna fecha cambia', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-109');
    $year = dateChangeYear($tenant);
    $probe = new DateChangeProbeCheck;
    app(AcademicYearDateChangeRegistry::class)->register($probe);

    test()->actingAs($admin)->patchJson(dateChangeUrl($tenant, $year->public_id), ['starts_on' => '2026-09-07'])->assertOk();

    expect($probe->received)->toHaveCount(1);
    $change = $probe->received[0];

    expect($change->year)->toBeInstanceOf(AcademicYearSummary::class)
        ->and($change->year->startsOn->toDateString())->toBe('2026-09-01')
        ->and($change->year->endsOn->toDateString())->toBe('2027-08-31')
        ->and($change->year->status)->toBe(AcademicYearStatus::Planificacion)
        ->and($change->year->publicId)->toBe($year->public_id)
        ->and($change->startsOn->toDateString())->toBe('2026-09-07')
        ->and($change->endsOn->toDateString())->toBe('2027-08-31');

    // Solo `code`, o las mismas fechas: no se ejecuta ninguna validación.
    test()->actingAs($admin)->patchJson(dateChangeUrl($tenant, $year->public_id), ['code' => 'otro-codigo'])->assertOk();
    test()->actingAs($admin)->patchJson(dateChangeUrl($tenant, $year->public_id), ['starts_on' => '2026-09-07', 'ends_on' => '2027-08-31'])->assertOk();

    expect($probe->received)->toHaveCount(1);
});

test('CA-CURSO-109 RN-CURSO-50: el alta, la activación y el cierre no ejecutan la validación del cambio de fechas', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-109b');
    $probe = new DateChangeProbeCheck('curso', 'test.date_change_rejected');
    app(AcademicYearDateChangeRegistry::class)->register($probe);

    $publicId = test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, '/academic-years'), ['code' => '2026-2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31'])
        ->assertCreated()->json('public_id');
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, "/academic-years/{$publicId}/status"), ['status' => 'activo'])->assertOk();
    test()->actingAs($admin)->postJson(coreApiUrl($tenant->slug, "/academic-years/{$publicId}/status"), ['status' => 'cerrado'])->assertOk();

    expect($probe->received)->toBe([]);
});

test('CA-CURSO-110 RN-CURSO-51 RN-CURSO-54: con la validación sin fallo el PATCH da 200 y audita starts_on y ends_on {from, to}', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-110');
    $year = dateChangeYear($tenant);
    app(AcademicYearDateChangeRegistry::class)->register(new DateChangeProbeCheck);

    test()->actingAs($admin)->patchJson(dateChangeUrl($tenant, $year->public_id), ['starts_on' => '2026-09-07', 'ends_on' => '2027-07-31'])
        ->assertOk()
        ->assertJsonPath('starts_on', '2026-09-07')
        ->assertJsonPath('ends_on', '2027-07-31');

    app(TenantContext::class)->runFor($tenant->id, function () use ($year): void {
        $log = AuditLog::query()->where('auditable_type', 'academic_year')->where('auditable_id', $year->id)->where('event', 'updated')->latest('id')->firstOrFail();

        expect($log->changes['starts_on']['from'])->toContain('2026-09-01')
            ->and($log->changes['starts_on']['to'])->toContain('2026-09-07')
            ->and($log->changes['ends_on']['from'])->toContain('2027-08-31')
            ->and($log->changes['ends_on']['to'])->toContain('2027-07-31');
    });
});

test('CA-CURSO-110 RN-CURSO-51: fechas inválidas por sí mismas (422) o curso activo (409 not_editable) no ejecutan la validación', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-110b');
    $year = dateChangeYear($tenant);
    $other = CursoTestHelpers::year($tenant, '2027-2028', AcademicYearStatus::Cerrado, '2027-09-01', '2028-08-31');
    $probe = new DateChangeProbeCheck('curso', 'test.date_change_rejected');
    app(AcademicYearDateChangeRegistry::class)->register($probe);

    test()->actingAs($admin)->patchJson(dateChangeUrl($tenant, $year->public_id), ['ends_on' => '2026-08-31'])
        ->assertStatus(422)->assertJsonPath('errors.ends_on.0.code', 'curso.validation.ends_before_start');
    test()->actingAs($admin)->patchJson(dateChangeUrl($tenant, $year->public_id), ['ends_on' => '2027-09-15'])
        ->assertStatus(422)->assertJsonPath('errors.starts_on.0.code', 'curso.validation.dates_overlap');

    CursoTestHelpers::setStatus($tenant, $year, AcademicYearStatus::Activo);
    test()->actingAs($admin)->patchJson(dateChangeUrl($tenant, $year->public_id), ['ends_on' => '2027-07-31'])
        ->assertStatus(409)->assertJsonPath('errors.academic_year.0.code', 'curso.conflict.not_editable');

    expect($probe->received)->toBe([])
        ->and($other->status)->toBe(AcademicYearStatus::Cerrado);
});

test('CA-CURSO-112 RN-CURSO-52 OPEN-CURSO-26: un módulo no contratado no valida; al contratarlo el mismo PATCH da 409', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-112');
    $year = dateChangeYear($tenant);
    $probe = new DateChangeProbeCheck('acad', 'test.date_change_rejected');
    app(AcademicYearDateChangeRegistry::class)->register($probe);

    test()->actingAs($admin)->patchJson(dateChangeUrl($tenant, $year->public_id), ['ends_on' => '2027-07-31'])->assertOk();

    expect($probe->received)->toBe([])
        ->and(yearState($tenant, $year)[1])->toBe('2027-07-31');

    // `module_subscriptions` es de la plataforma: sin actor autenticado (created_by).
    resetSessionState();
    contractModule($tenant, 'acad');

    test()->actingAs($admin)->patchJson(dateChangeUrl($tenant, $year->public_id), ['ends_on' => '2027-06-30'])
        ->assertStatus(409)
        ->assertJsonPath('errors.date_change.0.code', 'test.date_change_rejected');

    expect($probe->received)->toHaveCount(1)
        ->and(yearState($tenant, $year)[1])->toBe('2027-07-31');
});

test('CA-CURSO-114 INV-001: la validación solo ve datos de su centro; el public_id de un curso de otro centro es 404 y ninguna validación se ejecuta', function (): void {
    [$tenantA, $adminA] = provisionCoreTenant('cur-114a');
    [$tenantB] = provisionCoreTenant('cur-114b');
    $yearA = dateChangeYear($tenantA);
    $yearB = dateChangeYear($tenantB);

    $probe = new class implements AcademicYearDateChangeCheck
    {
        public int $seenYears = 0;

        public int $calls = 0;

        public function moduleCode(): string
        {
            return 'curso';
        }

        public function check(AcademicYearDateChange $change): ?AcademicYearDateChangeFailure
        {
            $this->calls++;
            $this->seenYears = AcademicYear::query()->count();

            return null;
        }
    };
    app(AcademicYearDateChangeRegistry::class)->register($probe);

    test()->actingAs($adminA)->patchJson(dateChangeUrl($tenantA, $yearA->public_id), ['starts_on' => '2026-10-01'])->assertOk();

    expect($probe->calls)->toBe(1)->and($probe->seenYears)->toBe(1);

    test()->actingAs($adminA)->patchJson(dateChangeUrl($tenantA, $yearB->public_id), ['starts_on' => '2026-10-01'])
        ->assertNotFound();

    expect($probe->calls)->toBe(1)
        ->and(yearState($tenantB, $yearB))->toBe(['2026-09-01', '2027-08-31', '2026-2027', 'planificacion']);
});

test('CA-CURSO-117 RN-CURSO-55 RN-CURSO-57: lockForShareById devuelve el resumen leído con FOR SHARE, también de un curso cerrado, y null si no existe', function (): void {
    [$tenant] = provisionCoreTenant('cur-117');
    $year = dateChangeYear($tenant);
    $closed = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Cerrado, '2025-09-01', '2026-06-30');

    app(TenantContext::class)->runFor($tenant->id, function () use ($year, $closed): void {
        DB::transaction(function () use ($year, $closed): void {
            $lock = app(AcademicYearSharedLock::class);
            $queries = [];
            DB::listen(function ($query) use (&$queries): void {
                $queries[] = $query->sql;
            });

            $summary = $lock->lockForShareById($year->id);

            expect($summary)->toBeInstanceOf(AcademicYearSummary::class)
                ->and($summary->publicId)->toBe($year->public_id)
                ->and($summary->code)->toBe('2026-2027')
                ->and($summary->status)->toBe(AcademicYearStatus::Planificacion)
                ->and($summary->startsOn->toDateString())->toBe('2026-09-01')
                ->and($summary->endsOn->toDateString())->toBe('2027-08-31')
                ->and($queries)->toHaveCount(1)
                ->and(strtolower($queries[0]))->toContain('for share');

            expect($lock->lockForShareById($closed->id)?->status)->toBe(AcademicYearStatus::Cerrado)
                ->and($lock->lockForShareById(987654321))->toBeNull();
        });
    });
});

test('CA-CURSO-117 RN-CURSO-55: no usa el memo de AcademicYearDirectory: consulta la base de datos en cada llamada', function (): void {
    [$tenant] = provisionCoreTenant('cur-117c');
    $year = dateChangeYear($tenant);

    app(TenantContext::class)->runFor($tenant->id, function () use ($year): void {
        $directory = app(AcademicYearDirectory::class);
        $directory->findById($year->id);

        DB::transaction(function () use ($year): void {
            $count = 0;
            DB::listen(function () use (&$count): void {
                $count++;
            });

            app(AcademicYearSharedLock::class)->lockForShareById($year->id);
            app(AcademicYearSharedLock::class)->lockForShareById($year->id);

            expect($count)->toBe(2);
        });
    });
});

test('CA-CURSO-118 RN-CURSO-57 INV-001: el identificador de un curso de otro centro devuelve null y no cambia ninguna fila', function (): void {
    [$tenantA] = provisionCoreTenant('cur-118a');
    [$tenantB] = provisionCoreTenant('cur-118b');
    dateChangeYear($tenantA);
    $yearB = dateChangeYear($tenantB);

    $result = app(TenantContext::class)->runFor($tenantA->id, fn () => DB::transaction(
        fn () => app(AcademicYearSharedLock::class)->lockForShareById($yearB->id),
    ));

    expect($result)->toBeNull()
        ->and(yearState($tenantB, $yearB))->toBe(['2026-09-01', '2027-08-31', '2026-2027', 'planificacion']);
});

test('CA-CURSO-121 RN-CURSO-60 RN-CURSO-30 OPEN-CURSO-27: una validación de cierre de un módulo no contratado no se ejecuta; contratado, el cierre da 409', function (): void {
    [$tenant, $admin] = provisionCoreTenant('cur-121');
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');

    app(AcademicYearClosureRegistry::class)->register(new class implements AcademicYearClosureCheck
    {
        public function moduleCode(): string
        {
            return 'acad';
        }

        public function check(AcademicYearSummary $year): ?AcademicYearClosureFailure
        {
            return new AcademicYearClosureFailure('test.closure_rejected', 'Cierre rechazado por la prueba.', ['n' => 2]);
        }
    });

    $statusUrl = coreApiUrl($tenant->slug, "/academic-years/{$year->public_id}/status");

    // Contratado: bloquea.
    contractModule($tenant, 'acad');
    test()->actingAs($admin)->postJson($statusUrl, ['status' => 'cerrado'])
        ->assertStatus(409)
        ->assertJsonPath('errors.closure.0.code', 'test.closure_rejected');
    expect(yearState($tenant, $year)[3])->toBe('activo');

    // Descontratado: no se ejecuta y el curso se cierra.
    resetSessionState();
    app(TenantContext::class)->runFor($tenant->id, function (): void {
        DB::connection('pgsql_platform')->table('module_subscriptions')->where('module_code', 'acad')->update(['enabled' => false]);
        Cache::forget('modules:acad:enabled');
    });

    test()->actingAs($admin)->postJson($statusUrl, ['status' => 'cerrado'])->assertOk();
    expect(yearState($tenant, $year)[3])->toBe('cerrado');
});
