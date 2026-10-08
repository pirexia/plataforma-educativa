<?php

use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Modules\Curso\Infrastructure\AcademicYearClosedTranslator;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\AcademicYearProbe;
use Tests\Support\CursoTestHelpers;

// REQ-CURSO-001, criterio de aceptación 1 de §5.28, RN-CURSO-20/-21/-23,
// ADR-057 (Anexo A: CA-057-01 a CA-057-05, CA-057-07 y CA-057-10), INV-015.
// Base de datos REAL (ADR-033 §10): el bloqueo lo impone un disparador de
// PostgreSQL y solo un motor real lo prueba. La «tabla sonda» se crea con
// el ayudante real de TenantMigration (funcional.md §1.3), así que lleva el
// disparador `academic_year_write_guard`.

beforeEach(function (): void {
    AcademicYearProbe::ensureTable();

    $this->tenant = Tenant::factory()->create();
    Cache::forget("tenant-resolution:{$this->tenant->slug}");
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/**
 * Prepara un curso con una fila viva y otra borrada lógicamente en la sonda
 * (mientras el curso admite escritura) y lo lleva después al estado pedido.
 *
 * @return array{year: AcademicYear, id: int, trashedId: int}
 */
function probeFixture(Tenant $tenant, AcademicYearStatus $finalStatus): array
{
    $year = CursoTestHelpers::year($tenant, '2025-2026', AcademicYearStatus::Activo);

    [$id, $trashedId] = app(TenantContext::class)->runFor($tenant->id, function () use ($year): array {
        $live = AcademicYearProbe::create(['name' => 'viva', 'academic_year_id' => $year->id]);
        $gone = AcademicYearProbe::create(['name' => 'borrada', 'academic_year_id' => $year->id]);
        $gone->delete();

        return [$live->id, $gone->id];
    });

    if ($finalStatus !== AcademicYearStatus::Activo) {
        CursoTestHelpers::setStatus($tenant, $year, $finalStatus);
    }

    return ['year' => $year, 'id' => $id, 'trashedId' => $trashedId];
}

/**
 * Cada camino de escritura de CA-CURSO-040 (funcional.md §13.3). Cada
 * closure recibe el curso, la fila viva y la borrada.
 *
 * @return array<string, Closure(AcademicYear, int, int): mixed>
 */
function writePaths(): array
{
    return [
        'save() de creación' => fn (AcademicYear $y, int $id) => (new AcademicYearProbe(['name' => 'nueva', 'academic_year_id' => $y->id]))->save(),
        'save() de modificación' => function (AcademicYear $y, int $id): void {
            $probe = AcademicYearProbe::query()->findOrFail($id);
            $probe->name = 'cambiada';
            $probe->save();
        },
        'borrado lógico' => fn (AcademicYear $y, int $id) => AcademicYearProbe::query()->findOrFail($id)->delete(),
        'restore()' => fn (AcademicYear $y, int $id, int $trashed) => AcademicYearProbe::withTrashed()->findOrFail($trashed)->restore(),
        'forceDelete()' => fn (AcademicYear $y, int $id) => AcademicYearProbe::query()->findOrFail($id)->forceDelete(),
        'Model::query()->update()' => fn (AcademicYear $y, int $id) => AcademicYearProbe::query()->whereKey($id)->update(['name' => 'masiva']),
        'Model::query()->delete()' => fn (AcademicYear $y, int $id) => AcademicYearProbe::query()->whereKey($id)->delete(),
        'actualización por relación' => fn (AcademicYear $y, int $id) => AcademicYearProbe::query()->findOrFail($id)->siblings()->update(['name' => 'relacion']),
        'DB::table()->insert()' => fn (AcademicYear $y, int $id) => DB::table(AcademicYearProbe::TABLE)->insert(['name' => 'sql', 'academic_year_id' => $y->id]),
        'DB::table()->update()' => fn (AcademicYear $y, int $id) => DB::table(AcademicYearProbe::TABLE)->where('id', $id)->update(['name' => 'sql']),
        'DB::table()->delete()' => fn (AcademicYear $y, int $id) => DB::table(AcademicYearProbe::TABLE)->where('id', $id)->delete(),
        'DB::statement (INSERT crudo)' => fn (AcademicYear $y, int $id) => DB::statement('insert into '.AcademicYearProbe::TABLE.' (name, academic_year_id) values (?, ?)', ['crudo', $y->id]),
        'DB::statement (UPDATE crudo)' => fn (AcademicYear $y, int $id) => DB::statement('update '.AcademicYearProbe::TABLE.' set name = ? where id = ?', ['crudo', $id]),
        'DB::statement (DELETE crudo)' => fn (AcademicYear $y, int $id) => DB::statement('delete from '.AcademicYearProbe::TABLE.' where id = ?', [$id]),
    ];
}

/** Ejecuta la escritura en un punto de guardado: un error de PostgreSQL no aborta la transacción del test. */
function attempt(Closure $write): ?QueryException
{
    try {
        DB::transaction($write);
    } catch (QueryException $e) {
        return $e;
    }

    return null;
}

function sqlState(?QueryException $e): ?string
{
    return $e === null ? null : ($e->errorInfo[0] ?? null);
}

/** Estado de la fila viva de la sonda, leído sin el scope de borrado lógico. */
function probeSnapshot(int $id): ?object
{
    return DB::table(AcademicYearProbe::TABLE)->where('id', $id)->first(['name', 'academic_year_id', 'deleted_at']);
}

foreach (writePaths() as $label => $_) {
    // CA-CURSO-040 / CA-057-01 (RN-CURSO-20, RN-CURSO-23): es el criterio de
    // aceptación 1 de §5.28 («modificar en un curso cerrado: el sistema lo
    // impide e indica el motivo») sobre el único recurso por curso que hay.
    test("CA-CURSO-040 CA-057-01 RN-CURSO-23 ADR-057: en un curso cerrado, «{$label}» falla con SQLSTATE YC001 y la fila no cambia", function () use ($label): void {
        ['year' => $year, 'id' => $id, 'trashedId' => $trashedId] = probeFixture($this->tenant, AcademicYearStatus::Cerrado);

        app(TenantContext::class)->enter($this->tenant->id);
        $before = probeSnapshot($id);
        $beforeTrashed = probeSnapshot($trashedId);
        $count = DB::table(AcademicYearProbe::TABLE)->count();

        $error = attempt(fn () => writePaths()[$label]($year, $id, $trashedId));

        expect(sqlState($error))->toBe(AcademicYearClosedTranslator::SQLSTATE)
            ->and($error->getMessage())->toContain("academic_year_closed:{$year->public_id}")
            ->and(probeSnapshot($id))->toEqual($before)
            ->and(probeSnapshot($trashedId))->toEqual($beforeTrashed)
            ->and(DB::table(AcademicYearProbe::TABLE)->count())->toBe($count);
    });

    // CA-CURSO-042 / CA-057-01: con el curso en `activo` o `planificacion`
    // los mismos caminos funcionan.
    foreach ([AcademicYearStatus::Activo, AcademicYearStatus::Planificacion] as $status) {
        test("CA-CURSO-042 CA-057-01 RN-CURSO-20: en un curso {$status->value}, «{$label}» se permite", function () use ($label, $status): void {
            ['year' => $year, 'id' => $id, 'trashedId' => $trashedId] = probeFixture($this->tenant, $status);

            app(TenantContext::class)->enter($this->tenant->id);

            expect(sqlState(attempt(fn () => writePaths()[$label]($year, $id, $trashedId))))->toBeNull();
        });
    }
}

test('CA-CURSO-040 ADR-057 §5.1: archivado es de solo lectura igual que cerrado', function (): void {
    ['year' => $year, 'id' => $id] = probeFixture($this->tenant, AcademicYearStatus::Archivado);

    app(TenantContext::class)->enter($this->tenant->id);

    expect(sqlState(attempt(fn () => DB::table(AcademicYearProbe::TABLE)->where('id', $id)->update(['name' => 'x']))))->toBe(AcademicYearClosedTranslator::SQLSTATE)
        ->and(sqlState(attempt(fn () => DB::table(AcademicYearProbe::TABLE)->insert(['name' => 'x', 'academic_year_id' => $year->id]))))->toBe(AcademicYearClosedTranslator::SQLSTATE);
});

test('CA-057-02 ADR-057 §5.1: mover una fila hacia o desde un curso cerrado falla con YC001', function (): void {
    $planning = CursoTestHelpers::year($this->tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-07-01', '2026-08-31');
    $closing = CursoTestHelpers::year($this->tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');

    [$inOpen, $inClosing] = app(TenantContext::class)->runFor($this->tenant->id, fn (): array => [
        AcademicYearProbe::create(['name' => 'abierta', 'academic_year_id' => $planning->id])->id,
        AcademicYearProbe::create(['name' => 'se cerrará', 'academic_year_id' => $closing->id])->id,
    ]);

    CursoTestHelpers::setStatus($this->tenant, $closing, AcademicYearStatus::Cerrado);

    app(TenantContext::class)->enter($this->tenant->id);

    $toClosed = attempt(fn () => DB::table(AcademicYearProbe::TABLE)->where('id', $inOpen)->update(['academic_year_id' => $closing->id]));
    $fromClosed = attempt(fn () => DB::table(AcademicYearProbe::TABLE)->where('id', $inClosing)->update(['academic_year_id' => $planning->id]));

    expect(sqlState($toClosed))->toBe(AcademicYearClosedTranslator::SQLSTATE)
        ->and(sqlState($fromClosed))->toBe(AcademicYearClosedTranslator::SQLSTATE)
        ->and(probeSnapshot($inOpen)->academic_year_id)->toBe($planning->id)
        ->and(probeSnapshot($inClosing)->academic_year_id)->toBe($closing->id);
});

test('CA-057-02 ADR-057 §5.1: mover una fila entre dos cursos abiertos se permite', function (): void {
    $planning = CursoTestHelpers::year($this->tenant, '2026-2027', AcademicYearStatus::Planificacion, '2026-07-01', '2026-08-31');
    $active = CursoTestHelpers::year($this->tenant, '2025-2026', AcademicYearStatus::Activo, '2025-09-01', '2026-06-30');

    $id = app(TenantContext::class)->runFor($this->tenant->id, fn (): int => AcademicYearProbe::create(['name' => 'm', 'academic_year_id' => $active->id])->id);

    app(TenantContext::class)->enter($this->tenant->id);

    expect(sqlState(attempt(fn () => DB::table(AcademicYearProbe::TABLE)->where('id', $id)->update(['academic_year_id' => $planning->id]))))->toBeNull()
        ->and(probeSnapshot($id)->academic_year_id)->toBe($planning->id);
});

test('CA-057-03 ADR-057 §5.2: el propietario de la tabla está exento (relleno de migración sobre un curso cerrado)', function (): void {
    // Todo por conexiones comprometidas (platform escribe, owner actualiza):
    // el propietario es otra sesión y no vería filas sin comprometer.
    $platform = DB::connection('pgsql_platform');
    $owner = DB::connection('pgsql_owner');
    $tenantId = $this->tenant->id;
    $now = now();

    $yearId = $platform->table('academic_years')->insertGetId([
        'tenant_id' => $tenantId, 'public_id' => (string) Str::ulid(), 'code' => '2024-2025',
        'starts_on' => '2024-09-01', 'ends_on' => '2025-06-30', 'status' => 'activo', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $rowId = $platform->table(AcademicYearProbe::TABLE)->insertGetId([
        'tenant_id' => $tenantId, 'name' => 'antes', 'academic_year_id' => $yearId, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $platform->table('academic_years')->where('id', $yearId)->update(['status' => 'cerrado']);

    try {
        // El rol de aplicación sí queda bloqueado, también con BYPASSRLS.
        $blocked = null;
        try {
            $platform->table(AcademicYearProbe::TABLE)->where('id', $rowId)->update(['name' => 'plataforma']);
        } catch (QueryException $e) {
            $blocked = $e;
        }
        expect(sqlState($blocked))->toBe(AcademicYearClosedTranslator::SQLSTATE);

        // FORCE RLS obliga también al propietario: necesita el GUC del tenant.
        $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
        $affected = $owner->table(AcademicYearProbe::TABLE)->where('academic_year_id', $yearId)->update(['name' => 'relleno']);
        $owner->statement("select set_config('app.tenant_id', '', false)");

        expect($affected)->toBe(1)
            ->and($platform->table(AcademicYearProbe::TABLE)->where('id', $rowId)->value('name'))->toBe('relleno');
    } finally {
        // Limpieza como propietario (exento): el rol de plataforma sigue
        // bloqueado sobre un curso cerrado, también para borrar.
        $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);
        $owner->table(AcademicYearProbe::TABLE)->where('tenant_id', $tenantId)->delete();
        $owner->statement("select set_config('app.tenant_id', '', false)");
        $platform->table('academic_years')->where('tenant_id', $tenantId)->delete();
    }
});

test('CA-057-04 ADR-057 §5.2: un academic_year_id inexistente lo rechaza la clave foránea (23503), no el disparador', function (): void {
    app(TenantContext::class)->enter($this->tenant->id);

    $error = attempt(fn () => DB::table(AcademicYearProbe::TABLE)->insert(['name' => 'huérfana', 'academic_year_id' => 999999999]));

    expect(sqlState($error))->toBe('23503');
});

test('CA-057-07 ADR-057 §5.2: paridad SQL ↔ PHP, la función bloquea si y solo si el enumerado declara el estado de solo lectura', function (): void {
    // Un único curso al que se le fija cada estado por UPDATE directo
    // (`academic_years` no lleva el disparador y su CHECK admite los cuatro).
    $year = CursoTestHelpers::year($this->tenant, 'paridad', AcademicYearStatus::Planificacion);

    app(TenantContext::class)->enter($this->tenant->id);

    foreach (AcademicYearStatus::cases() as $status) {
        DB::table('academic_years')->where('id', $year->id)->update(['status' => $status->value]);

        $error = attempt(fn () => DB::table(AcademicYearProbe::TABLE)->insert(['name' => 'p', 'academic_year_id' => $year->id]));

        expect(sqlState($error) === AcademicYearClosedTranslator::SQLSTATE)->toBe($status->isReadOnly(), "estado «{$status->value}»");
    }

    expect(AcademicYearStatus::readOnlyValues())->toBe(['cerrado', 'archivado']);
});

test('CA-057-10 ADR-057 §5.1: ningún rol de aplicación tiene TRUNCATE sobre una tabla con academic_year_id', function (): void {
    $tables = array_map(
        fn (object $r): string => $r->tabla,
        DB::connection('pgsql_owner')->select(<<<'SQL'
            SELECT c.relname AS tabla
            FROM pg_attribute a
            JOIN pg_class c ON c.oid = a.attrelid AND c.relkind IN ('r', 'p')
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
            WHERE a.attname = 'academic_year_id' AND NOT a.attisdropped
            ORDER BY 1
            SQL),
    );

    expect($tables)->toContain(AcademicYearProbe::TABLE);

    foreach ($tables as $table) {
        foreach (['plataforma_app', 'plataforma_platform'] as $role) {
            $has = DB::connection('pgsql_owner')->selectOne('select has_table_privilege(?, ?, ?) as ok', [$role, "public.{$table}", 'TRUNCATE'])->ok;

            expect($has)->toBeFalse("{$role} no debe tener TRUNCATE sobre {$table}");
        }
    }
});

test('CA-CURSO-041 CA-057-05 RN-CURSO-21 ADR-057 §5.5: por HTTP la escritura bloqueada responde 409 academic-year-closed, en los cuatro idiomas', function (string $locale, string $title, string $fragment): void {
    ['year' => $year, 'id' => $id] = probeFixture($this->tenant, AcademicYearStatus::Cerrado);

    // El idioma se resuelve contra `active_locales` del centro.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => TenantSetting::forceCreate([
        'tenant_id' => $this->tenant->id, 'default_locale' => 'es-ES', 'active_locales' => ['es-ES', 'en', 'de', 'fr'],
        'timezone' => 'Europe/Madrid', 'currency' => 'EUR', 'autonomous_community' => null,
    ]));

    Route::middleware(['resolve-tenant', 'resolve-locale'])->post('/api/v1/_test/curso-guard', function () use ($id) {
        // Punto de guardado: en producción cada petición tiene su conexión y
        // el error de PostgreSQL no afecta a nada más; aquí comparte la
        // transacción envolvente del test.
        DB::transaction(fn () => AcademicYearProbe::query()->findOrFail($id)->update(['name' => 'por http']));

        return response()->json(['ok' => true]);
    });

    $response = test()
        ->withHeader('Accept-Language', $locale)
        ->postJson('http://'.$this->tenant->slug.'.'.config('tenancy.base_domain').'/api/v1/_test/curso-guard')
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'urn:pge:error:academic-year-closed')
        ->assertJsonPath('title', $title)
        ->assertJsonPath('status', 409)
        ->assertJsonPath('errors.academic_year.0.code', 'curso.academic_year_closed')
        ->assertJsonPath('errors.academic_year.0.params', ['code' => '2025-2026', 'status' => 'cerrado']);

    expect($response->json('detail'))->toContain('2025-2026')->toContain($fragment)
        ->and($response->json('errors.academic_year.0.message'))->toContain('2025-2026');
})->with([
    'es-ES' => ['es-ES', 'El curso académico es de solo lectura', 'solo lectura'],
    'en' => ['en', 'The academic year is read-only', 'read-only'],
    'de' => ['de', 'Das Schuljahr ist schreibgeschützt', 'schreibgeschützt'],
    'fr' => ['fr', "L'année scolaire est en lecture seule", 'lecture seule'],
]);

test('CA-CURSO-041 RN-CURSO-21 api.md §4: si no se puede resolver el curso responde igualmente 409 con el mismo type y un detail sin código', function (): void {
    Route::middleware(['resolve-tenant', 'resolve-locale'])->post('/api/v1/_test/curso-guard-anon', function () {
        // Error de motor sin public_id reconocible en el mensaje (p. ej.
        // transacción abortada): la traducción no puede resolver el curso y
        // degrada a un detail sin código.
        $pdo = new PDOException('academic_year_closed:');
        $pdo->errorInfo = [AcademicYearClosedTranslator::SQLSTATE, 7, 'academic_year_closed:'];

        throw new QueryException('pgsql', 'insert', [], $pdo);
    });

    test()->withHeader('Accept-Language', 'es-ES')
        ->postJson('http://'.$this->tenant->slug.'.'.config('tenancy.base_domain').'/api/v1/_test/curso-guard-anon')
        ->assertStatus(409)
        ->assertJsonPath('type', 'urn:pge:error:academic-year-closed')
        ->assertJsonPath('detail', 'El curso está cerrado y sus datos son de solo lectura. No se pueden crear, modificar ni eliminar datos de un curso cerrado.')
        ->assertJsonPath('errors.academic_year.0.code', 'curso.academic_year_closed')
        ->assertJsonPath('errors.academic_year.0.params', []);
});

test('CA-057-05 RN-CURSO-21: cualquier otro QueryException no se traduce y sigue siendo un 500', function (): void {
    Route::middleware(['resolve-tenant', 'resolve-locale'])->post('/api/v1/_test/curso-guard-otro', function () {
        $pdo = new PDOException('otro');
        $pdo->errorInfo = ['23503', 7, 'otro'];

        throw new QueryException('pgsql', 'insert', [], $pdo);
    });

    test()->postJson('http://'.$this->tenant->slug.'.'.config('tenancy.base_domain').'/api/v1/_test/curso-guard-otro')
        ->assertStatus(500)
        ->assertJsonPath('type', 'urn:pge:error:internal');
});

test('CA-058-02 ADR-058: la clase del SQLSTATE propio no está en los rangos reservados ni coincide con ninguna clase de PostgreSQL', function (): void {
    $sqlstate = AcademicYearClosedTranslator::SQLSTATE;
    $class = substr($sqlstate, 0, 2);

    // Clases definidas por PostgreSQL 17 (apéndice A, «PostgreSQL Error Codes»).
    $postgresClasses = [
        '00', '01', '02', '03', '08', '09', '0A', '0B', '0F', '0L', '0P', '0Z',
        '20', '21', '22', '23', '24', '25', '26', '27', '28', '2B', '2D', '2F',
        '34', '38', '39', '3B', '3D', '3F', '40', '42', '44', '53', '54', '55',
        '57', '58', '72', 'F0', 'HV', 'P0', 'XX',
    ];

    // Lo que emite la función instalada, no un literal repetido en el test.
    $definition = (string) DB::connection('pgsql_owner')
        ->selectOne("select pg_get_functiondef('app.assert_academic_year_writable()'::regprocedure) as def")->def;
    expect($definition)->toContain($sqlstate);

    // La lista de arriba es la de PostgreSQL 17: al subir de versión mayor este
    // test falla a propósito hasta que alguien la repase contra el apéndice A.
    $major = (int) (DB::connection('pgsql_owner')->selectOne('show server_version_num')->server_version_num / 10000);
    expect($major)->toBe(17);

    expect($sqlstate)->toMatch('/^[0-9A-Z]{5}$/')
        // Regla del estándar SQL: 0-4 y A-H quedan reservadas a clases estándar.
        ->and($class[0])->not->toMatch('/[0-4A-H]/')
        ->and($postgresClasses)->not->toContain($class)
        ->and($class)->toBe('YC');
});
