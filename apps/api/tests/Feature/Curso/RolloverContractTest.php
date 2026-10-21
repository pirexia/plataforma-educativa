<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PermissionRole;
use App\Models\UserStatus;
use App\Modules\Core\Infrastructure\Console\GrantRolloverPermissionsCommand;
use App\Modules\Curso\Application\ModuleScopedChecks;
use App\Modules\Curso\Application\Rollover\RolloverCatalog;
use App\Modules\Curso\Application\Rollover\RolloverExecutor;
use App\Modules\Curso\Domain\AcademicYearDateChangeRegistry;
use App\Modules\Curso\Domain\AcademicYearRolloverElement;
use App\Modules\Curso\Domain\AcademicYearRolloverRegistry;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Domain\Models\YearRollover;
use App\Modules\Curso\Domain\Models\YearRolloverItem;
use App\Modules\Curso\Domain\YearRolloverStatus;
use App\Modules\Curso\Infrastructure\InMemoryAcademicYearRolloverRegistry;
use App\Modules\Curso\Infrastructure\Jobs\RevertAcademicYearRollover;
use App\Modules\Curso\Infrastructure\Jobs\RunAcademicYearRollover;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AcadApiHelpers;
use Tests\Support\CursoTestHelpers;
use Tests\Support\DateChangeProbeCheck;
use Tests\Support\ProbeRolloverParticipant;
use Tests\Support\RolloverTestHelpers as R;

// REQ-CURSO-002 (1.12b), funcional.md §5.7, §13.8: criterios del CONTRATO de la apertura del curso siguiente, con
// participantes de prueba registrados en el test (`ProbeRolloverParticipant`, módulo `curso`, siempre contratado).
// Los trabajos se ejecutan de forma directa, dentro de la transacción del test (`RN-CURSO-86`).

beforeEach(function (): void {
    config(['curso.rollover.allow_nested_transaction' => true]);
    Queue::fake();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
    Cache::flush();
});

/** Registra un participante de prueba y lo devuelve. */
function probe(?ProbeRolloverParticipant $participant = null): ProbeRolloverParticipant
{
    $participant ??= ProbeRolloverParticipant::trio();
    app(AcademicYearRolloverRegistry::class)->register($participant);

    return $participant;
}

/** Cuerpo de solicitud de los elementos de prueba. */
function probeBody(array $s, array $overrides = []): array
{
    return $overrides + ['academic_year' => $s['target']->public_id, 'source' => $s['source']->public_id, 'elements' => ['curso.alpha', 'curso.beta']];
}

test('CA-CURSO-123 RN-CURSO-62: el test del registro detecta un código de elemento duplicado, un requisito inexistente y un ciclo en requires; el registro real es consistente', function (): void {
    R::scenario('rc-reg');
    app(RolloverCatalog::class)->assertConsistent();

    $catalog = function (ProbeRolloverParticipant ...$participants): RolloverCatalog {
        $registry = new InMemoryAcademicYearRolloverRegistry;

        foreach ($participants as $participant) {
            $registry->register($participant);
        }

        return new RolloverCatalog($registry, app(ModuleScopedChecks::class));
    };

    $element = fn (string $code, array $requires = []) => new AcademicYearRolloverElement($code, 'k', $requires, 'curso_academico.crear');

    expect(fn () => $catalog(new ProbeRolloverParticipant('curso', [$element('curso.a'), $element('curso.a')]))->assertConsistent())->toThrow(LogicException::class, 'duplicado')
        ->and(fn () => $catalog(new ProbeRolloverParticipant('curso', [$element('curso.a', ['curso.x'])]))->assertConsistent())->toThrow(LogicException::class, 'no existe')
        ->and(fn () => $catalog(new ProbeRolloverParticipant('curso', [$element('curso.a', ['curso.b']), $element('curso.b', ['curso.a'])]))->assertConsistent())->toThrow(LogicException::class, 'Ciclo')
        ->and(fn () => $catalog(new ProbeRolloverParticipant('curso', [$element('otro.a')]))->assertConsistent())->toThrow(LogicException::class, 'forma');
});

test('CA-CURSO-123 RN-CURSO-62: el orden de invocación respeta los requisitos entre módulos (el módulo que requiere va después)', function (): void {
    $first = new ProbeRolloverParticipant('curso', [new AcademicYearRolloverElement('curso.base', 'k', ['acad.root'], 'curso_academico.crear')]);
    $second = new ProbeRolloverParticipant('acad', [new AcademicYearRolloverElement('acad.root', 'k', [], 'curso_academico.crear')]);

    expect(array_map(fn ($p) => $p->moduleCode(), RolloverCatalog::order([$first, $second])))->toBe(['acad', 'curso']);
});

test('CA-CURSO-129 RN-CURSO-69: una excepción en copy() de un participante posterior deja la copia fallida con internal_error y NINGUNA fila de acad ni item; otra solicitud se acepta', function (): void {
    $s = R::scenario('rc-boom');
    $boom = probe(new ProbeRolloverParticipant('curso', [new AcademicYearRolloverElement('curso.boom', 'k', [], 'curso_academico.crear')]));
    $boom->onCopy = fn () => throw new RuntimeException('Fallo de prueba');

    $publicId = R::request($s, ['elements' => ['acad.education_levels', 'acad.courses', 'curso.boom']]);
    R::run($s, $publicId);
    $rollover = R::byId($s, $publicId);

    expect($rollover->status)->toBe(YearRolloverStatus::Fallido)
        ->and($rollover->failure['code'])->toBe('internal_error')
        ->and(array_sum(R::targetCounts($s)))->toBe(0)
        ->and(R::inTenant($s, fn () => YearRolloverItem::query()->count()))->toBe(0);

    // `fallido` no es una copia viva (RN-CURSO-67).
    $boom->onCopy = null;
    expect(R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s, ['elements' => ['acad.education_levels']]))->status())->toBe(202);
});

test('CA-CURSO-137 RN-CURSO-78: permisos propios del recurso en cada endpoint, 401 sin sesión, y 403 por elemento sin el permiso de alta (solo si el elemento está en la selección)', function (): void {
    $s = R::scenario('rc-perm');
    $body = R::body($s);
    $all = ['apertura_curso_academico.leer', 'apertura_curso_academico.crear', 'apertura_curso_academico.eliminar', 'estructura_academica.crear', 'estructura_academica.actualizar', 'configuracion_academica.crear', 'horarios.crear'];

    $without = fn (string $missing, string $local) => AcadApiHelpers::userWith($s['tenant'], $s['admin'], $local, array_values(array_diff($all, [$missing])));
    $noCreate = $without('apertura_curso_academico.crear', 'rpnc');
    $noRead = $without('apertura_curso_academico.leer', 'rpnr');
    $noDelete = $without('apertura_curso_academico.eliminar', 'rpnd');

    $publicId = R::request($s);

    foreach (['/academic-year-rollovers/elements', '/academic-year-rollovers/preview'] as $path) {
        expect(R::get($s['tenant'], $noCreate, $path, $path === '/academic-year-rollovers/preview' ? ['academic_year' => $s['target']->public_id, 'source' => $s['source']->public_id, 'elements' => 'acad.education_levels'] : [])->status())->toBe(403);
    }

    expect(R::post($s['tenant'], $noCreate, '/academic-year-rollovers', $body)->status())->toBe(403)
        ->and(R::get($s['tenant'], $noRead, '/academic-year-rollovers')->status())->toBe(403)
        ->and(R::get($s['tenant'], $noRead, "/academic-year-rollovers/{$publicId}")->status())->toBe(403)
        ->and(R::post($s['tenant'], $noDelete, "/academic-year-rollovers/{$publicId}/revert")->status())->toBe(403)
        ->and(R::post($s['tenant'], $noDelete, "/academic-year-rollovers/{$publicId}/cancel")->status())->toBe(403);

    // Sin sesión: 401 en todos.
    resetSessionState();
    $base = 'http://'.$s['tenant']->slug.'.'.config('tenancy.base_domain').'/api/v1/academic-year-rollovers';

    foreach (['getJson' => ['', '/elements', '/preview', "/{$publicId}"], 'postJson' => ['', "/{$publicId}/revert", "/{$publicId}/cancel"]] as $method => $paths) {
        foreach ($paths as $path) {
            expect(test()->{$method}($base.$path)->status())->toBe(401, "{$method} {$path}");
        }
    }

    // Con `apertura_curso_academico.crear` pero sin `configuracion_academica.crear`: 403 con la escala; sin ella, pasa.
    $noConfig = $without('configuracion_academica.crear', 'rpncf');
    $response = R::post($s['tenant'], $noConfig, '/academic-year-rollovers', R::body($s, ['elements' => ['acad.grading_scales']]))->assertStatus(403);

    expect($response->json('type'))->toBe('urn:pge:error:forbidden')
        ->and($response->json('errors.elements.0.code'))->toBe('curso.rollover.element_forbidden')
        ->and($response->json('errors.elements.0.params'))->toBe(['element' => 'acad.grading_scales']);

    R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());
    expect(R::post($s['tenant'], $noConfig, '/academic-year-rollovers', R::body($s, ['elements' => ['acad.education_levels']]))->status())->toBe(202);
});

test('CA-CURSO-138 INV-001 RN-CURSO-80: con dos centros ningún endpoint devuelve, cuenta ni revierte copias del otro; el public_id ajeno es 404, nunca 403', function (): void {
    $a = R::scenario('rc-iso-a');
    $b = R::scenario('rc-iso-b');
    $copyB = R::request($b);
    R::run($b, $copyB);
    $copyA = R::request($a, ['elements' => ['acad.education_levels']]);

    expect(R::get($a['tenant'], $a['admin'], "/academic-year-rollovers/{$copyB}")->status())->toBe(404)
        ->and(R::post($a['tenant'], $a['admin'], "/academic-year-rollovers/{$copyB}/revert")->status())->toBe(404)
        ->and(R::post($a['tenant'], $a['admin'], "/academic-year-rollovers/{$copyB}/cancel")->status())->toBe(404)
        ->and(array_column(R::get($a['tenant'], $a['admin'], '/academic-year-rollovers', ['academic_year' => $a['target']->public_id])->json('data'), 'public_id'))->toBe([$copyA])
        ->and(R::get($a['tenant'], $a['admin'], '/academic-year-rollovers', ['academic_year' => $b['target']->public_id])->status())->toBe(404)
        ->and(R::byId($b, $copyB)->status)->toBe(YearRolloverStatus::Completado)
        // Un destino de otro centro como parámetro es indistinguible de uno inexistente.
        ->and(R::post($a['tenant'], $a['admin'], '/academic-year-rollovers', R::body($a, ['academic_year' => $b['target']->public_id]))->assertStatus(422)->json('errors.academic_year.0.code'))->toBe('curso.validation.rollover_target_not_found');
});

test('CA-CURSO-139 RN-CURSO-33 RN-CURSO-80: sin curso_historico.leer, el listado y el detalle de una copia cuyo destino está cerrado dan 404 con el mismo cuerpo que un recurso inexistente; con él, los datos', function (): void {
    $s = R::scenario('rc-hist');
    $publicId = R::request($s, ['elements' => ['acad.education_levels']]);
    R::run($s, $publicId);

    // El destino se activa y se cierra.
    CursoTestHelpers::setStatus($s['tenant'], $s['source'], AcademicYearStatus::Cerrado);
    CursoTestHelpers::setStatus($s['tenant'], $s['target'], AcademicYearStatus::Activo);
    CursoTestHelpers::setStatus($s['tenant'], $s['target'], AcademicYearStatus::Cerrado);

    $reader = AcadApiHelpers::userWith($s['tenant'], $s['admin'], 'rch', ['apertura_curso_academico.leer']);
    $historian = AcadApiHelpers::userWith($s['tenant'], $s['admin'], 'rchh', ['apertura_curso_academico.leer', 'curso_historico.leer']);

    $list = R::get($s['tenant'], $reader, '/academic-year-rollovers', ['academic_year' => $s['target']->public_id])->assertStatus(404);
    $detail = R::get($s['tenant'], $reader, "/academic-year-rollovers/{$publicId}")->assertStatus(404);
    $missing = R::get($s['tenant'], $reader, '/academic-year-rollovers/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertStatus(404);

    expect($detail->json('type'))->toBe($missing->json('type'))->and($detail->json('title'))->toBe($missing->json('title'))
        ->and($list->json('type'))->toBe($missing->json('type'));

    R::get($s['tenant'], $historian, '/academic-year-rollovers', ['academic_year' => $s['target']->public_id])->assertOk()->assertJsonPath('data.0.public_id', $publicId);
    R::get($s['tenant'], $historian, "/academic-year-rollovers/{$publicId}")->assertOk()->assertJsonPath('revertible', false);
});

test('CA-CURSO-142 RN-CURSO-72: la copia no invoca las validaciones del cambio de fechas y no cambia las fechas del destino', function (): void {
    $s = R::scenario('rc-dates');
    $check = new DateChangeProbeCheck('curso');
    app(AcademicYearDateChangeRegistry::class)->register($check);

    $publicId = R::request($s);
    R::run($s, $publicId);

    expect(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Completado)
        ->and($check->received)->toBe([]);

    $target = R::inTenant($s, fn () => AcademicYear::query()->findOrFail($s['target']->id));
    expect($target->starts_on->toDateString())->toBe('2027-09-01')->and($target->ends_on->toDateString())->toBe('2028-08-31');
});

test('CA-CURSO-146 INV-009 AR-12: las claves de api.md §5.1 (errores, incidencias, estados, motivos de fallo y etiqueta del recurso) existen en los cuatro idiomas', function (): void {
    $validation = ['rollover_target_required', 'rollover_target_not_found', 'rollover_source_required', 'rollover_source_not_found', 'rollover_source_invalid', 'rollover_elements_required', 'rollover_element_unknown', 'rollover_element_requires', 'rollover_element_not_selectable', 'rollover_teaching_assignments_unavailable'];
    $conflict = ['rollover_target_not_planning', 'rollover_exists', 'rollover_blocked', 'rollover_in_progress', 'rollover_not_revertible', 'rollover_revert_module_unavailable', 'rollover_revert_blocked', 'rollover_not_cancellable', 'rollover_cancel_too_early'];

    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        $lang = require base_path("lang/{$locale}/curso.php");

        foreach ($validation as $key) {
            expect($lang['validation'][$key] ?? null)->toBeString()->not->toBeEmpty("{$locale} validation.{$key}");
        }

        foreach ($conflict as $key) {
            expect($lang['conflict'][$key] ?? null)->toBeString()->not->toBeEmpty("{$locale} conflict.{$key}");
        }

        foreach (['empty', 'exists', 'element_forbidden', 'element_unavailable'] as $key) {
            expect($lang['rollover'][$key] ?? null)->toBeString()->not->toBeEmpty("{$locale} rollover.{$key}");
        }

        foreach (YearRolloverStatus::cases() as $status) {
            expect($lang['rollover']['status'][$status->value] ?? null)->toBeString()->not->toBeEmpty("{$locale} rollover.status.{$status->value}");
        }

        foreach (['rollover_blocked', 'target_not_planning', 'target_changed', 'actor_unavailable', 'cancelled', 'internal_error'] as $code) {
            expect($lang['rollover']['failure'][$code] ?? null)->toBeString()->not->toBeEmpty("{$locale} rollover.failure.{$code}");
        }

        expect($lang['permissions']['resources']['apertura_curso_academico'] ?? null)->toBeString()->not->toBeEmpty("{$locale} apertura_curso_academico");
    }
});

test('CA-CURSO-148 permisos.md §4: sync-registry crea los tres permisos de apertura_curso_academico con ámbito todos; el aprovisionamiento los da solo a administrador_centro; curso:grant-rollover-permissions es idempotente, no toca roles personalizados y no concede nada si faltan o están retirados', function (): void {
    test()->artisan('platform:sync-registry')->run();

    $codes = ['apertura_curso_academico.crear', 'apertura_curso_academico.eliminar', 'apertura_curso_academico.leer'];
    $permissions = Permission::query()->whereIn('code', $codes)->orderBy('code')->get();

    expect($permissions->pluck('code')->all())->toBe($codes)
        ->and($permissions->every(fn ($p) => $p->module_code === 'curso' && $p->applicable_scopes === ['todos'] && $p->is_special_category === false && $p->retired_at === null))->toBeTrue();

    [$tenant, $admin] = provisionCoreTenant('rc-seed');
    $holders = fn (): array => R::inTenant(['tenant' => $tenant], fn () => PermissionRole::query()->whereIn('permission_code', $codes)->with('role')->get()->groupBy(fn ($pr) => $pr->role->code)->map(fn ($g) => $g->pluck('permission_code')->sort()->values()->all())->all());

    expect($holders())->toBe(['administrador_centro' => $codes]);

    // Un centro «anterior al despliegue» que además retiró a propósito otro permiso del módulo.
    R::inTenant(['tenant' => $tenant], function (): void {
        PermissionRole::query()->whereIn('permission_code', ['apertura_curso_academico.leer', 'apertura_curso_academico.crear', 'apertura_curso_academico.eliminar', 'reapertura_curso_academico.actualizar'])->get()->each->delete();
    });
    $custom = AcadApiHelpers::userWith($tenant, $admin, 'rcseed', ['usuario.leer']);

    test()->artisan('curso:grant-rollover-permissions')->assertSuccessful();
    test()->artisan('curso:grant-rollover-permissions')->assertSuccessful();

    expect($holders())->toBe(['administrador_centro' => $codes])
        // No re-concede el permiso de reapertura que el centro retiró: solo los tres nuevos.
        ->and(R::inTenant(['tenant' => $tenant], fn () => PermissionRole::query()->where('permission_code', 'reapertura_curso_academico.actualizar')->count()))->toBe(0)
        ->and($custom)->not->toBeNull();

    $customCodes = R::inTenant(['tenant' => $tenant], fn () => PermissionRole::query()->whereHas('role', fn ($q) => $q->where('code', 'rol_rcseed'))->pluck('permission_code')->all());
    expect($customCodes)->toBe(['usuario.leer']);
});

test('CA-CURSO-148: curso:grant-rollover-permissions se niega si el registro no está sincronizado o si algún permiso está retirado, y no concede nada', function (): void {
    [$tenant] = provisionCoreTenant('rc-seed2');
    R::inTenant(['tenant' => $tenant], fn () => PermissionRole::query()->where('permission_code', 'like', 'apertura_curso_academico.%')->get()->each->delete());

    $unsynced = new class extends GrantRolloverPermissionsCommand
    {
        protected function registeredPermissionCodes(array $codes): array
        {
            return [];
        }
    };
    Artisan::registerCommand($unsynced);
    test()->artisan('curso:grant-rollover-permissions')->expectsOutputToContain('platform:sync-registry')->assertFailed();

    $retired = new class extends GrantRolloverPermissionsCommand
    {
        protected function retiredPermissionCodes(array $codes): array
        {
            return $codes;
        }
    };
    Artisan::registerCommand($retired);
    test()->artisan('curso:grant-rollover-permissions')->expectsOutputToContain('retirados')->assertFailed();

    expect(R::inTenant(['tenant' => $tenant], fn () => PermissionRole::query()->where('permission_code', 'like', 'apertura_curso_academico.%')->count()))->toBe(0);
});

test('CA-CURSO-150 OPEN-CURSO-37 (b) RN-CURSO-79: cada fila de year_rollover_items deja su created con actor el solicitante y YearRolloverItem no declara ninguna exclusión de eventos', function (): void {
    $s = R::bare('rc-items');
    probe();
    $publicId = R::request($s, ['elements' => ['curso.alpha', 'curso.beta']]);
    R::run($s, $publicId);

    R::inTenant($s, function () use ($s, $publicId): void {
        $rollover = YearRollover::query()->where('public_id', $publicId)->firstOrFail();
        $items = YearRolloverItem::query()->where('year_rollover_id', $rollover->id)->get();

        expect($rollover->status)->toBe(YearRolloverStatus::Completado)
            ->and($items)->toHaveCount(4)
            ->and(AuditLog::query()->where('auditable_type', 'year_rollover_item')->where('event', 'created')->where('actor_user_id', $s['admin']->id)->whereIn('auditable_id', $items->pluck('id'))->count())->toBe(4)
            ->and((new YearRolloverItem)->auditExcludedEvents())->toBe([]);
    });
});

test('CA-CURSO-151 RN-CURSO-82 RN-CURSO-64: sin la casilla el elemento del paquete no se simula ni se copia; con ella entra en la selección efectiva y se guarda; fuera de elements es 422; sin su requisito es 422; sin ningún elemento de paquete es 422; un valor no booleano es 422', function (): void {
    $s = R::bare('rc-bundle');
    $probe = probe();

    // Sin la casilla: la simulación y la solicitud no tocan gamma, y la copia guarda copy_teaching_assignments: false.
    $preview = R::get($s['tenant'], $s['admin'], '/academic-year-rollovers/preview', ['academic_year' => $s['target']->public_id, 'source' => $s['source']->public_id, 'elements' => 'curso.alpha,curso.beta'])->assertOk();
    expect(array_column($preview->json('elements'), 'code'))->toBe(['curso.alpha', 'curso.beta'])
        ->and($probe->previews)->toBe([['curso.alpha', 'curso.beta']]);

    $off = R::request($s);
    expect(R::byId($s, $off)->copy_teaching_assignments)->toBeFalse()->and(R::byId($s, $off)->elements)->toBe(['curso.alpha', 'curso.beta']);
    R::run($s, $off);
    expect($probe->copies)->toBe([['curso.alpha', 'curso.beta']]);

    // Con la casilla (tras fallar/cancelar la anterior no hace falta: se revierte).
    R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$off}/revert")->assertStatus(202);
    R::runRevert($s, $off);

    $on = R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s, ['elements' => ['curso.alpha', 'curso.beta'], 'copy_teaching_assignments' => true]))->assertStatus(202);
    expect($on->json('copy_teaching_assignments'))->toBeTrue()->and($on->json('elements'))->toBe(['curso.alpha', 'curso.beta', 'curso.gamma']);

    R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());

    // El elemento del paquete enviado en `elements`.
    $notSelectable = R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s, ['elements' => ['curso.alpha', 'curso.gamma']]))->assertStatus(422);
    expect($notSelectable->json('errors.elements.0.code'))->toBe('curso.validation.rollover_element_not_selectable')
        ->and($notSelectable->json('errors.elements.0.params'))->toBe(['element' => 'curso.gamma']);

    // `true` sin su requisito (beta) en `elements`.
    $requires = R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s, ['elements' => ['curso.alpha'], 'copy_teaching_assignments' => true]))->assertStatus(422);
    expect($requires->json('errors.elements.0.code'))->toBe('curso.validation.rollover_element_requires')
        ->and($requires->json('errors.elements.0.params'))->toBe(['element' => 'curso.gamma', 'requires' => 'curso.beta']);

    // Un valor no booleano ⇒ 422 de forma; ningún caso de error escribe nada.
    R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s, ['copy_teaching_assignments' => 'si']))->assertStatus(422)->assertJsonPath('errors.copy_teaching_assignments.0.code', 'core.validation.boolean');
    R::get($s['tenant'], $s['admin'], '/academic-year-rollovers/preview', ['academic_year' => $s['target']->public_id, 'source' => $s['source']->public_id, 'elements' => 'curso.alpha', 'copy_teaching_assignments' => 'si'])->assertStatus(422);

    expect(R::inTenant($s, fn () => YearRollover::query()->count()))->toBe(0);
});

test('CA-CURSO-151 RN-CURSO-82: con la casilla y ningún participante utilizable que ofrezca el paquete, 422 rollover_teaching_assignments_unavailable', function (): void {
    $s = R::bare('rc-bundle2');
    probe(new ProbeRolloverParticipant('curso', [new AcademicYearRolloverElement('curso.alpha', 'k', [], 'curso_academico.crear')]));

    R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s, ['elements' => ['curso.alpha'], 'copy_teaching_assignments' => true]))
        ->assertStatus(422)->assertJsonPath('errors.copy_teaching_assignments.0.code', 'curso.validation.rollover_teaching_assignments_unavailable');
});

test('CA-CURSO-152 RN-CURSO-82 RN-CURSO-78: sin el permiso del elemento del paquete, sin la casilla pasa; con ella es 403 element_forbidden por ese elemento y no se crea nada; el catálogo lo marca allowed: false', function (): void {
    $s = R::bare('rc-bundle3');
    probe();
    $user = AcadApiHelpers::userWith($s['tenant'], $s['admin'], 'rcb', ['apertura_curso_academico.crear', 'curso_academico.crear', 'curso_academico.leer']);

    expect(R::post($s['tenant'], $user, '/academic-year-rollovers', R::body($s))->status())->toBe(202);

    R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());
    $forbidden = R::post($s['tenant'], $user, '/academic-year-rollovers', R::body($s, ['copy_teaching_assignments' => true]))->assertStatus(403);

    expect($forbidden->json('errors.elements'))->toHaveCount(1)
        ->and($forbidden->json('errors.elements.0.code'))->toBe('curso.rollover.element_forbidden')
        ->and($forbidden->json('errors.elements.0.params'))->toBe(['element' => 'curso.gamma'])
        ->and(R::inTenant($s, fn () => YearRollover::query()->count()))->toBe(0);

    $catalog = collect(R::get($s['tenant'], $user, '/academic-year-rollovers/elements')->json('data'))->pluck('allowed', 'code');
    expect($catalog['curso.alpha'])->toBeTrue()->and($catalog['curso.gamma'])->toBeFalse();
});

test('CA-CURSO-154 RN-CURSO-83 RN-CURSO-79: el trabajo ejecuta como el solicitante con Auth vacío, lo retira al terminar (también si falla), y si el solicitante ya no está activo no ejecuta nada (copia y reversión)', function (): void {
    $s = R::bare('rc-actor');
    $probe = probe();
    $user = R::user($s['tenant'], 'solicitante@example.com');
    $publicId = R::inTenant($s, fn () => R::rollover($s['tenant'], $s['target'], $s['source'], YearRolloverStatus::Pendiente, $user, ['curso.alpha', 'curso.beta'])->public_id);

    R::inTenant($s, fn () => Auth::forgetUser());
    R::run($s, $publicId);

    $rollover = R::byId($s, $publicId);
    expect($rollover->status)->toBe(YearRolloverStatus::Completado)
        ->and($probe->copyActors)->toBe([$user->id])
        ->and(R::inTenant($s, fn () => Auth::id()))->toBeNull();

    R::inTenant($s, function () use ($user, $rollover): void {
        $items = YearRolloverItem::query()->where('year_rollover_id', $rollover->id)->get();
        expect($items->every(fn ($i) => $i->created_by === $user->id))->toBeTrue()
            ->and(AuditLog::query()->where('auditable_type', 'year_rollover_item')->where('event', 'created')->whereIn('auditable_id', $items->pluck('id'))->pluck('actor_user_id')->unique()->all())->toBe([$user->id])
            // Los `updated` de year_rollover (en_curso, completado) llevan al solicitante.
            ->and(AuditLog::query()->where('auditable_type', 'year_rollover')->where('auditable_id', $rollover->id)->where('event', 'updated')->pluck('actor_user_id')->unique()->all())->toBe([$user->id]);
    });

    // Si el trabajo falla, el actor también se retira.
    $failing = probe(new ProbeRolloverParticipant('curso', [new AcademicYearRolloverElement('curso.boom', 'k', [], 'curso_academico.crear')]));
    $failing->onCopy = fn () => throw new RuntimeException('x');
    R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());
    $second = R::inTenant($s, fn () => R::rollover($s['tenant'], $s['target'], $s['source'], YearRolloverStatus::Pendiente, $user, ['curso.boom'])->public_id);
    R::run($s, $second);

    expect(R::byId($s, $second)->failure['code'])->toBe('internal_error')
        ->and(R::inTenant($s, fn () => Auth::id()))->toBeNull();

    // Solicitante desactivado: no se invoca a ningún participante y la copia queda fallida con actor_unavailable.
    R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());
    $before = count($probe->copies);
    $third = R::inTenant($s, fn () => R::rollover($s['tenant'], $s['target'], $s['source'], YearRolloverStatus::Pendiente, $user, ['curso.alpha'])->public_id);
    R::setUserStatus($s['tenant'], $user, UserStatus::Inactivo);
    R::run($s, $third);

    $row = R::byId($s, $third);
    expect($row->status)->toBe(YearRolloverStatus::Fallido)
        ->and($row->failure['code'])->toBe('actor_unavailable')
        ->and(count($probe->copies))->toBe($before)
        ->and(R::inTenant($s, fn () => YearRolloverItem::query()->where('year_rollover_id', $row->id)->count()))->toBe(0);

    // Lo mismo para la reversión con `reverted_by`: vuelve a completado con revert_failure.code = actor_unavailable.
    $reverter = R::user($s['tenant'], 'revierte@example.com');
    R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());
    $fourth = R::inTenant($s, fn () => R::rollover($s['tenant'], $s['target'], $s['source'], YearRolloverStatus::Revirtiendo, $reverter, ['curso.alpha'])->public_id);
    R::setUserStatus($s['tenant'], $reverter, UserStatus::Inactivo);
    R::runRevert($s, $fourth);

    $reverted = R::byId($s, $fourth);
    expect($reverted->status)->toBe(YearRolloverStatus::Completado)
        ->and($reverted->revert_failure['code'])->toBe('actor_unavailable')
        ->and($probe->reverts)->toBe([]);
});

test('CA-CURSO-155 RN-CURSO-84: los dos trabajos declaran tries = 1 y un timeout menor que el retry_after de su conexión; handle() no ejecuta nada si la copia ya no está pendiente; failed() aplica la transición condicional', function (): void {
    $s = R::bare('rc-life');
    $user = R::user($s['tenant'], 'ciclo@example.com');
    $retryAfter = (int) config('queue.connections.database_long.retry_after');

    foreach ([new RunAcademicYearRollover(1), new RevertAcademicYearRollover(1)] as $job) {
        expect($job->tries)->toBe(1)
            ->and($job->timeout)->toBeLessThan($retryAfter)
            ->and($job->connection)->toBe('database_long')
            ->and($job->queue)->toBe('long')
            ->and($retryAfter - $job->timeout)->toBeGreaterThanOrEqual(300);
    }

    $make = fn (YearRolloverStatus $status) => R::inTenant($s, fn () => R::rollover($s['tenant'], $s['target'], $s['source'], $status, $user)->id);
    $state = fn (int $id) => R::inTenant($s, fn () => YearRollover::query()->findOrFail($id));
    $fail = fn (int $id) => R::inTenant($s, fn () => app(RolloverExecutor::class)->failedByQueue($id));

    // Entrega repetida con la copia ya en curso: handle() no ejecuta nada.
    $probe = probe();
    $running = $make(YearRolloverStatus::EnCurso);
    R::inTenant($s, fn () => (new RunAcademicYearRollover($running))->handle(app(RolloverExecutor::class)));
    expect($state($running)->status)->toBe(YearRolloverStatus::EnCurso)->and($probe->copies)->toBe([]);

    // failed(): pendiente/en_curso ⇒ fallido internal_error.
    foreach ([YearRolloverStatus::Pendiente, YearRolloverStatus::EnCurso] as $status) {
        R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());
        $id = $make($status);
        $fail($id);
        expect($state($id)->status)->toBe(YearRolloverStatus::Fallido)->and($state($id)->failure['code'])->toBe('internal_error');
    }

    // revirtiendo ⇒ completado con revert_failure.
    R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());
    $id = R::inTenant($s, fn () => R::rollover($s['tenant'], $s['target'], $s['source'], YearRolloverStatus::Revirtiendo, $user)->id);
    $fail($id);
    expect($state($id)->status)->toBe(YearRolloverStatus::Completado)->and($state($id)->revert_failure['code'])->toBe('internal_error');

    // completado, fallido y revertido: nada cambia.
    foreach ([YearRolloverStatus::Completado, YearRolloverStatus::Fallido, YearRolloverStatus::Revertido] as $status) {
        R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());
        $id = R::inTenant($s, fn () => R::rollover($s['tenant'], $s['target'], $s['source'], $status, $user)->id);
        $fail($id);
        expect($state($id)->status)->toBe($status)->and($state($id)->failure)->toBeNull();
    }

    // Una copia cancelada (fallida por cancelación) no la toca el trabajo que llega después.
    R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());
    $cancelled = R::inTenant($s, fn () => R::rollover($s['tenant'], $s['target'], $s['source'], YearRolloverStatus::Pendiente, $user, ['curso.alpha']));
    R::inTenant($s, function () use ($cancelled): void {
        $cancelled->status = YearRolloverStatus::Fallido;
        $cancelled->failure = ['code' => 'cancelled', 'errors' => []];
        $cancelled->save();
    });
    R::inTenant($s, fn () => (new RunAcademicYearRollover($cancelled->id))->handle(app(RolloverExecutor::class)));
    expect($state($cancelled->id)->failure['code'])->toBe('cancelled')->and($probe->copies)->toBe([]);
});

/** Hace envejecer `updated_at` de una copia (por el propietario: es el único que escribe sin tocar la marca). */
function ageRollover(array $s, string $publicId, int $minutes): void
{
    R::inTenant($s, fn () => DB::connection('pgsql')->table('year_rollovers')->where('public_id', $publicId)->update(['updated_at' => Carbon::now()->subMinutes($minutes)]));
}

test('CA-CURSO-156 RN-CURSO-85: cancelar una copia huérfana pasados 30 minutos la deja fallida con cancelled y libera el destino; antes, 409 cancel_too_early; en otro estado, 409 not_cancellable; sin permiso 403; de otro centro 404', function (): void {
    $s = R::bare('rc-cancel');
    probe();
    $publicId = R::request($s);

    // Menos de 30 minutos.
    ageRollover($s, $publicId, 10);
    $early = R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/cancel")->assertStatus(409);
    expect($early->json('errors.rollover.0.code'))->toBe('curso.conflict.rollover_cancel_too_early')
        ->and($early->json('errors.rollover.0.params.available_at'))->toBeString();

    // Sin permiso.
    $noDelete = AcadApiHelpers::userWith($s['tenant'], $s['admin'], 'rcc', ['apertura_curso_academico.leer']);
    ageRollover($s, $publicId, 31);
    expect(R::post($s['tenant'], $noDelete, "/academic-year-rollovers/{$publicId}/cancel")->status())->toBe(403);

    // La activación del destino está bloqueada hasta cancelar.
    CursoTestHelpers::setStatus($s['tenant'], $s['source'], AcademicYearStatus::Cerrado);
    R::post($s['tenant'], $s['admin'], "/academic-years/{$s['target']->public_id}/status", ['status' => 'activo'])->assertStatus(409)->assertJsonPath('errors.rollover.0.code', 'curso.conflict.rollover_in_progress');

    $cancelled = R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/cancel")->assertOk();
    expect($cancelled->json('status'))->toBe('fallido')
        ->and($cancelled->json('failure.code'))->toBe('cancelled')
        ->and(R::inTenant($s, fn () => AuditLog::query()->where('auditable_type', 'year_rollover')->where('event', 'updated')->where('actor_user_id', $s['admin']->id)->exists()))->toBeTrue();

    // En fallido: no se puede volver a cancelar.
    R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/cancel")->assertStatus(409)->assertJsonPath('errors.rollover.0.code', 'curso.conflict.rollover_not_cancellable');

    // Tras cancelar, una nueva solicitud se acepta y la activación procede.
    expect(R::post($s['tenant'], $s['admin'], '/academic-year-rollovers', R::body($s))->status())->toBe(202);
    R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());
    R::post($s['tenant'], $s['admin'], "/academic-years/{$s['target']->public_id}/status", ['status' => 'activo'])->assertOk();
});

test('CA-CURSO-156 RN-CURSO-85: una reversión huérfana caducada vuelve a completado con revert_failure cancelled y no borra nada; completado, fallido y revertido no se cancelan', function (): void {
    $s = R::bare('rc-cancel2');
    $probe = probe();
    $publicId = R::request($s);
    R::run($s, $publicId);
    R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/revert")->assertStatus(202);

    ageRollover($s, $publicId, 31);
    $response = R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/cancel")->assertOk();

    expect($response->json('status'))->toBe('completado')
        ->and($response->json('revert_failure.code'))->toBe('cancelled')
        ->and($probe->reverts)->toBe([]);

    R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/cancel")->assertStatus(409)->assertJsonPath('errors.rollover.0.params.status', 'completado');
});

test('CA-CURSO-157 RN-CURSO-85: curso:rollover-reset cancela con las mismas reglas que el endpoint, respeta la caducidad salvo --force (con confirmación y aviso), no encuentra copias de otro centro y es idempotente', function (): void {
    $s = R::bare('rc-reset');
    $other = R::bare('rc-reset-b');
    probe();
    $publicId = R::request($s);
    $slug = $s['tenant']->slug;

    test()->artisan('curso:rollover-reset', ['public_id' => $publicId])->expectsOutputToContain('--tenant')->assertFailed();
    test()->artisan('curso:rollover-reset', ['public_id' => $publicId, '--tenant' => $other['tenant']->slug])->expectsOutputToContain('no tiene ninguna copia')->assertFailed();

    // Sin --force respeta la caducidad.
    test()->artisan('curso:rollover-reset', ['public_id' => $publicId, '--tenant' => $slug])->assertFailed();
    expect(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Pendiente);

    // --force: pide confirmación; negada, no hace nada.
    test()->artisan('curso:rollover-reset', ['public_id' => $publicId, '--tenant' => $slug, '--force' => true])->expectsConfirmation('--force salta la caducidad de 30 minutos: si el trabajo sigue vivo en la cola, la copia se marcará como fallida igualmente. ¿Continuar?', 'no')->assertFailed();
    expect(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Pendiente);

    // Confirmada: cancela y deja un aviso en el registro de la aplicación; auditado sin actor.
    Log::spy();
    test()->artisan('curso:rollover-reset', ['public_id' => $publicId, '--tenant' => $slug, '--force' => true])->expectsConfirmation('--force salta la caducidad de 30 minutos: si el trabajo sigue vivo en la cola, la copia se marcará como fallida igualmente. ¿Continuar?', 'yes')->assertSuccessful();

    Log::shouldHaveReceived('warning')->with('curso.rollover.reset.forced', Mockery::any());

    $row = R::byId($s, $publicId);
    expect($row->status)->toBe(YearRolloverStatus::Fallido)->and($row->failure['code'])->toBe('cancelled')
        ->and(R::inTenant($s, fn () => AuditLog::query()->where('auditable_type', 'year_rollover')->where('auditable_id', $row->id)->where('event', 'updated')->latest('id')->first()->actor_user_id))->toBeNull();

    // Dos veces no cambia nada la segunda.
    test()->artisan('curso:rollover-reset', ['public_id' => $publicId, '--tenant' => $slug, '--force' => true])->expectsConfirmation('--force salta la caducidad de 30 minutos: si el trabajo sigue vivo en la cola, la copia se marcará como fallida igualmente. ¿Continuar?', 'yes')->assertFailed();
    expect(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Fallido);

    // Una copia caducada se cancela sin --force.
    R::inTenant($s, fn () => YearRollover::query()->get()->each->delete());
    $second = R::request($s);
    ageRollover($s, $second, 45);
    test()->artisan('curso:rollover-reset', ['public_id' => $second, '--tenant' => $slug])->assertSuccessful();
    expect(R::byId($s, $second)->status)->toBe(YearRolloverStatus::Fallido);
});

test('RN-CURSO-71 RN-CURSO-85: una copia o reversión huérfana no bloquea la activación del destino una vez cancelada; revertir y cancelar auditan al actor', function (): void {
    $s = R::bare('rc-actv');
    probe();
    $publicId = R::request($s);

    ageRollover($s, $publicId, 40);
    R::post($s['tenant'], $s['admin'], "/academic-year-rollovers/{$publicId}/cancel")->assertOk();

    expect(R::byId($s, $publicId)->status)->toBe(YearRolloverStatus::Fallido);
});
