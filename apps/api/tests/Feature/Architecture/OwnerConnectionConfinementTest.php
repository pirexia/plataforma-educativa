<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcademicYearProbe;
use Tests\Support\PhpScanner;

pest()->group('arch');

// AR-14 (issue #383, ADR-057 §5.2, ADR-056 §3.2, INV-001, INV-003, INV-015).
// La conexión `pgsql_owner` es la del PROPIETARIO del esquema: está exenta del
// disparador `academic_year_write_guard` (ADR-057 §5.2: la exención es por
// `pg_class.relowner`). NO está exenta de RLS (las tablas tienen FORCE ROW
// LEVEL SECURITY, también para el propietario); BYPASSRLS lo tiene
// `plataforma_platform`, no el propietario. En tiempo de ejecución solo el texto del
// ADR prohibía usarla sobre tablas de curso; esta regla lo comprueba:
//
// 1. Ningún fichero de `app/` menciona la cadena constante `pgsql_owner`
//    (ni como `DB::connection('pgsql_owner')` ni como
//    `config('database.connections.pgsql_owner')`) salvo (a) los ficheros de
//    migración (`app/Modules/*/Database/migrations/`, que solo corren por
//    CLI con `--database=pgsql_owner`) y (b) la lista CERRADA y nominal de
//    abajo.
// 2. Ninguna función SECURITY DEFINER propiedad del rol propietario de una
//    tabla con `academic_year_id` menciona esa tabla: correría como
//    propietario y saltaría el disparador.
//
// Límites declarados (ADR-056 §3.3, no se promete más de lo que se comprueba):
// el escáner ve la cadena constante, no una construida (`'pgsql_'.'owner'`)
// ni el nombre leído de configuración/entorno; la comprobación de funciones
// busca el nombre de la tabla en el cuerpo (`prosrc`), no dependencias
// indirectas (función que llama a otra). Tampoco ve las acciones
// referenciales (`ON DELETE CASCADE`), que PostgreSQL ejecuta como el
// propietario de la tabla hija: eso lo cubre AR-13 sobre el esquema (ver
// `ARCHITECTURE.md §3.4` y el issue #383). Límites no cubiertos por AR-14
// (declarados, sin ampliar alcance): solo escanea `app/` (no `database/`,
// `routes/`, `config/` ni `tests/`), y `TenantMigration` sigue siendo
// invocable desde fuera de una migración.

/**
 * Excepciones de AR-14: lista CERRADA y nominal (ADR-056 §3.2), fichero
 * relativo a `app/` => motivo. Solo puede reducirse; añadir una entrada exige
 * especificación aprobada expresamente por el usuario (OPEN-056-02). Medida
 * el 2026-10-08: ninguna de estas tablas tiene `academic_year_id`.
 *
 * @return array<string, string>
 */
function ownerConnectionExceptions(): array
{
    return [
        'Support/Modules/SyncModuleRegistry.php' => 'platform:sync-registry escribe las tablas de referencia modules, permissions y feature_flags (sin RLS ni tenant) solo por el propietario (ADR-034 §5)',
        'Support/Tenancy/TenantMigration.php' => 'ayudante DDL de migraciones: crea tablas, RLS y el disparador de curso; solo lo invocan migraciones ejecutadas con --database=pgsql_owner',
        'Modules/Auth/Infrastructure/Jobs/PurgeLoginAttempts.php' => 'purga por retención de login_attempts, tabla de solo anexar sin DELETE para el rol de aplicación (REQ-AUTH-001); sin academic_year_id',
        'Modules/Auth/Infrastructure/Jobs/PurgeSamlAuthRequests.php' => 'purga por retención de saml_auth_requests (REQ-AUTH-004); sin academic_year_id',
        'Modules/Auth/Infrastructure/Jobs/PurgeSamlConsumedAssertions.php' => 'purga por retención de saml_consumed_assertions (REQ-AUTH-004); sin academic_year_id',
    ];
}

/**
 * ¿Es un fichero de migración de módulo? Solo corren por CLI con la conexión
 * del propietario.
 */
function isModuleMigration(string $relativePath): bool
{
    return preg_match('#^Modules/[^/]+/Database/migrations/#', $relativePath) === 1;
}

/**
 * @param  array<string, string>  $sources  ruta relativa a `app/` => código
 * @param  list<string>  $allowed
 * @return list<string> ficheros que mencionan `pgsql_owner` sin estar permitidos
 */
function unsanctionedOwnerConnectionUsers(array $sources, array $allowed): array
{
    $found = [];

    foreach ($sources as $path => $source) {
        if (isModuleMigration($path) || in_array($path, $allowed, true)) {
            continue;
        }

        if (mentionsOwnerConnection($source)) {
            $found[] = $path;
        }
    }

    return $found;
}

function mentionsOwnerConnection(string $source): bool
{
    foreach (PhpScanner::stringLiterals($source) as $literal) {
        if (str_contains($literal, 'pgsql_owner')) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, string> ruta relativa a `app/` => código
 */
function ownerScanSources(): array
{
    $appPath = app_path();
    $sources = [];

    foreach (PhpScanner::phpFiles($appPath) as $file) {
        $sources[substr($file, strlen($appPath) + 1)] = file_get_contents($file);
    }

    return $sources;
}

test('AR-14 #383 ADR-057: ningún fichero de app/ fuera de migraciones y de la lista cerrada usa la conexión pgsql_owner', function (): void {
    $sources = ownerScanSources();

    expect(count($sources))->toBeGreaterThan(100);

    $violations = unsanctionedOwnerConnectionUsers($sources, array_keys(ownerConnectionExceptions()));

    expect($violations)->toBe([], "uso de pgsql_owner en tiempo de ejecución fuera de la lista cerrada de AR-14 (el propietario salta RLS y el bloqueo de cursos cerrados, ADR-057 §5.2):\n".implode("\n", $violations));
});

test('AR-14 #383 ADR-056 §3.2: cada excepción existe y sigue usando pgsql_owner (la lista solo puede reducirse)', function (): void {
    $sources = ownerScanSources();

    foreach (ownerConnectionExceptions() as $file => $motive) {
        expect($motive)->not->toBe('')
            ->and(isset($sources[$file]))->toBeTrue("{$file}: el fichero ya no existe, retirarlo de la lista de AR-14")
            ->and(mentionsOwnerConnection($sources[$file]))->toBeTrue("{$file} ya no usa pgsql_owner: retirarlo de la lista de AR-14");
    }

    // Ratchet real: la lista solo puede reducirse (<= 5), nunca crecer.
    expect(count(ownerConnectionExceptions()))->toBeLessThanOrEqual(5, 'la lista de excepciones de AR-14 solo puede reducirse: tenía 5 entradas y ahora tiene más');
});

test('AR-14 #383 CA-056-14 control negativo: detecta la mención real y no la de un comentario, ni una migración, ni un fichero de la lista', function (): void {
    $sources = [
        'A/Mala.php' => "<?php\nclass Mala { function f() { return DB::connection('pgsql_owner')->table('x'); } }",
        'A/MalaConfig.php' => "<?php\nclass MalaConfig { function f() { return config(\"database.connections.pgsql_owner\"); } }",
        'A/Comentario.php' => "<?php\n// DB::connection('pgsql_owner')\n/* 'pgsql_owner' */\nclass Comentario {}",
        'A/Otra.php' => "<?php\nclass Otra { function f() { return DB::connection('pgsql'); } }",
        'Modules/M/Database/migrations/2026_01_01_000000_x.php' => "<?php\nreturn DB::connection('pgsql_owner');",
        'A/Listada.php' => "<?php\nclass Listada { function f() { return DB::connection('pgsql_owner'); } }",
    ];

    expect(unsanctionedOwnerConnectionUsers($sources, ['A/Listada.php']))->toBe(['A/Mala.php', 'A/MalaConfig.php']);
});

/**
 * Funciones SECURITY DEFINER cuyo propietario es propietario de una tabla con
 * `academic_year_id` y que mencionan esa tabla en su cuerpo.
 *
 * @return list<string> `esquema.función → tabla`
 */
function definerFunctionsTouchingAcademicYearTables(string $functionPrefix = ''): array
{
    $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT pn.nspname || '.' || p.proname || ' -> ' || c.relname AS hallazgo
        FROM pg_proc p
        JOIN pg_namespace pn ON pn.oid = p.pronamespace
        JOIN pg_attribute a ON a.attname = 'academic_year_id' AND NOT a.attisdropped
        JOIN pg_class c ON c.oid = a.attrelid AND c.relkind IN ('r', 'p') AND c.relowner = p.proowner
        JOIN pg_namespace cn ON cn.oid = c.relnamespace AND cn.nspname = 'public'
        WHERE p.prosecdef
          AND p.prokind IN ('f', 'p')
          AND pn.nspname NOT IN ('pg_catalog', 'information_schema')
          AND p.proname LIKE ?
          AND p.prosrc ~* ('(^|[^a-z0-9_])' || c.relname || '([^a-z0-9_]|$)')
        ORDER BY 1
        SQL, [$functionPrefix.'%']);

    return array_map(static fn (object $r): string => $r->hallazgo, $rows);
}

test('AR-14 #383 #385 ADR-057 §5.2: ninguna función SECURITY DEFINER del propietario menciona una tabla con academic_year_id', function (): void {
    // La tabla sonda garantiza que la regla no pasa en vacío (el esquema real
    // no tiene aún ninguna tabla de curso hasta 1.11).
    AcademicYearProbe::ensureTable();

    $tables = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid AND c.relkind IN ('r','p') WHERE a.attname = 'academic_year_id' AND NOT a.attisdropped")->n;

    expect((int) $tables)->toBeGreaterThan(0, 'AR-14 pasaría en vacío: ninguna tabla con academic_year_id');

    expect(definerFunctionsTouchingAcademicYearTables())->toBe([], 'funciones SECURITY DEFINER del propietario que tocan tablas de curso: correrían como propietario y saltarían academic_year_write_guard (ADR-057 §5.2)');
});

test('AR-14 #385 control negativo: detecta la función DEFINER que menciona la tabla de curso y no la INVOKER ni la que menciona otra tabla', function (): void {
    $owner = DB::connection('pgsql_owner');
    $p = 'arch_control_def_';

    $owner->beginTransaction();

    try {
        TenantMigration::tenantTable("{$p}curso", function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
        });

        $owner->statement("CREATE FUNCTION app.{$p}definer_mala() RETURNS void LANGUAGE sql SECURITY DEFINER AS \$\$ DELETE FROM {$p}curso \$\$");
        $owner->statement("CREATE FUNCTION app.{$p}invoker_ok() RETURNS void LANGUAGE sql SECURITY INVOKER AS \$\$ DELETE FROM {$p}curso \$\$");
        $owner->statement("CREATE FUNCTION app.{$p}definer_otra_tabla() RETURNS void LANGUAGE sql SECURITY DEFINER AS \$\$ SELECT 1 FROM tenants \$\$");
        $owner->statement("CREATE FUNCTION app.{$p}definer_prefijo() RETURNS void LANGUAGE plpgsql SECURITY DEFINER AS \$\$ BEGIN PERFORM 1 FROM {$p}curso_otra; END \$\$");

        expect(definerFunctionsTouchingAcademicYearTables($p))->toBe(["app.{$p}definer_mala -> {$p}curso"]);
    } finally {
        $owner->rollBack();
    }
});
