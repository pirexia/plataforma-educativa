<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Issue #412 / #407 (REQ-ACAD-001, 1.11-a), ADR-034 §7, CLAUDE.md §9,
// INV-015. La migración *expand* 2026_10_12_100000_add_tenant_foreign_key_indexes
// converge una base ya migrada con la de una instalación nueva: crea con el
// nombre de TenantMigration::indexName() el índice (tenant_id, columna) donde
// ningún índice no parcial lo cubre, es idempotente y no duplica nada.
//
// El test arranca ejecutando up(): así vale igual en una base migrada con la
// versión anterior (la crea) que en una ya convergida (no hace nada). Opera
// sobre las tablas reales del esquema y lo deja como lo encontró (las bases de
// test son por proceso). Cierra antes la transacción del test: un CREATE INDEX
// CONCURRENTLY no debe esperar a una transacción abierta.

function indexMigration(): Migration
{
    static $migration = null;

    return $migration ??= require database_path('migrations/2026_10_12_100000_add_tenant_foreign_key_indexes.php');
}

/** @return list<array{0: string, 1: string}> */
function tenantForeignKeyPairs(): array
{
    return (new ReflectionClassConstant(get_class(indexMigration()), 'FOREIGN_KEYS'))->getValue();
}

/** @return array<string, string> nombre => definición de todos los índices de public */
function allIndexDefinitions(): array
{
    $rows = DB::connection('pgsql_owner')->select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' ORDER BY indexname");

    return array_combine(array_map(fn ($r) => $r->indexname, $rows), array_map(fn ($r) => $r->indexdef, $rows));
}

/** ¿Algún índice válido, btree, no parcial y sin expresiones empieza por (tenant_id, columna)? Excluye el de nombre `$ignoreName`. */
function coveredByAnotherIndex(string $table, string $column, string $ignoreName): bool
{
    return DB::connection('pgsql_owner')->selectOne(<<<'SQL'
        SELECT 1 AS found FROM pg_index i
        JOIN pg_class ic ON ic.oid = i.indexrelid
        JOIN pg_am am ON am.oid = ic.relam AND am.amname = 'btree'
        WHERE i.indrelid = ?::regclass AND i.indisvalid AND i.indpred IS NULL AND i.indexprs IS NULL
          AND ic.relname <> ? AND i.indnkeyatts >= 2
          AND (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = i.indkey[0]) = 'tenant_id'
          AND (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = i.indkey[1]) = ?
        LIMIT 1
        SQL, [$table, $ignoreName, $column]) !== null;
}

beforeEach(function (): void {
    $connection = DB::connection();

    while ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }

    indexMigration()->up();
});

test('#412 CA-ACAD-038: el inventario son las 22 llamadas a tenantForeignId() de migraciones mezcladas, todas con clave foránea compuesta real', function (): void {
    $pairs = tenantForeignKeyPairs();

    expect($pairs)->toHaveCount(22);

    foreach ($pairs as [$table, $column]) {
        $fk = DB::connection('pgsql_owner')->selectOne(
            "SELECT 1 AS found FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'f' AND pg_get_constraintdef(oid) LIKE ?",
            [$table, "FOREIGN KEY (tenant_id, {$column}) REFERENCES %(tenant_id, id)%"],
        );

        expect($fk)->not->toBeNull("{$table}.{$column}: no hay FK compuesta (tenant_id, {$column})");
    }
});

test('#412: tras la migración, cada clave foránea tiene un índice no parcial que empieza por (tenant_id, columna)', function (): void {
    foreach (tenantForeignKeyPairs() as [$table, $column]) {
        $name = TenantMigration::indexName($table, $column);

        expect(coveredByAnotherIndex($table, $column, '#ninguno'))->toBeTrue("{$table}.{$column} sin índice cubriente");
        expect(strlen($name))->toBeLessThanOrEqual(63);
    }
});

test('#412: la migración es idempotente: una segunda ejecución no cambia ni un índice', function (): void {
    $before = allIndexDefinitions();

    indexMigration()->up();
    indexMigration()->up();

    expect(allIndexDefinitions())->toBe($before);
});

test('#412 convergencia: desde una base «anterior» crea exactamente los índices de las claves sin cobertura, con el nombre y la definición de una instalación nueva', function (): void {
    $pairs = tenantForeignKeyPairs();

    // Estado de una instalación nueva (o ya convergida): los índices que el ayudante crea.
    $fresh = [];

    foreach ($pairs as [$table, $column]) {
        $name = TenantMigration::indexName($table, $column);
        $definition = allIndexDefinitions()[$name] ?? null;

        if ($definition !== null) {
            $fresh[$name] = $definition;
        }
    }

    try {
        // Estado de una base migrada con la versión anterior: sin esos índices.
        foreach (array_keys($fresh) as $name) {
            DB::connection('pgsql_owner')->statement("DROP INDEX IF EXISTS {$name}");
        }

        $expectedCreated = [];
        $expectedAbsent = [];

        foreach ($pairs as [$table, $column]) {
            $name = TenantMigration::indexName($table, $column);
            coveredByAnotherIndex($table, $column, $name) ? $expectedAbsent[] = $name : $expectedCreated[] = $name;
        }

        // Inventario exacto (#412): 18 a crear y 4 ya cubiertas por un índice completo a mano.
        // `saml_identity_provider_settings` NO está entre las cubiertas: su único índice con
        // (tenant_id, identity_provider_id) es parcial (`WHERE deleted_at IS NULL`).
        expect($expectedCreated)->toHaveCount(18)
            ->and($expectedAbsent)->toBe([
                TenantMigration::indexName('data_exports', 'requested_by'),
                TenantMigration::indexName('user_known_devices', 'user_id'),
                TenantMigration::indexName('mfa_resets', 'user_id'),
                TenantMigration::indexName('saml_consumed_assertions', 'identity_provider_id'),
            ])
            ->and(array_keys($fresh))->toEqualCanonicalizing($expectedCreated);

        indexMigration()->up();

        $after = allIndexDefinitions();

        foreach ($expectedCreated as $name) {
            expect($after)->toHaveKey($name)
                // Misma definición que la de una instalación nueva.
                ->and($after[$name])->toBe($fresh[$name] ?? null);
        }

        foreach ($expectedAbsent as $name) {
            expect($after)->not->toHaveKey($name);
        }
    } finally {
        // Se deja la base como una instalación nueva.
        foreach ($fresh as $name => $definition) {
            DB::connection('pgsql_owner')->statement(str_replace('CREATE INDEX', 'CREATE INDEX IF NOT EXISTS', $definition));
        }
    }
});

test('#412: sobre un esquema ya completo (instalación nueva) la migración no duplica índices', function (): void {
    foreach (tenantForeignKeyPairs() as [$table, $column]) {
        $duplicates = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
            SELECT count(*) AS n FROM pg_index i
            WHERE i.indrelid = ?::regclass AND i.indpred IS NULL AND i.indexprs IS NULL AND i.indnkeyatts = 2
              AND (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = i.indkey[0]) = 'tenant_id'
              AND (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = i.indkey[1]) = ?
            SQL, [$table, $column])->n;

        expect((int) $duplicates)->toBeLessThanOrEqual(1, "{$table}.{$column}: índices (tenant_id, {$column}) duplicados");
    }
});

test('#412: down() elimina los 18 índices que up() puede crear (no toca los de las 4 claves ya cubiertas) y up() los restablece', function (): void {
    $before = allIndexDefinitions();

    try {
        indexMigration()->down();

        $names = array_map(fn (array $pair): string => TenantMigration::indexName($pair[0], $pair[1]), tenantForeignKeyPairs());

        expect(array_intersect($names, array_keys(allIndexDefinitions())))->toBe([])
            // Los índices manuales que cubren las 4 claves siguen ahí.
            ->and(allIndexDefinitions())->toHaveKeys(['data_exports_tenant_requested_by_idx', 'user_known_devices_tenant_user_last_seen_idx', 'mfa_resets_tenant_user_performed_idx', 'saml_consumed_assertions_tenant_provider_assertion_unique']);
    } finally {
        indexMigration()->up();

    }

    expect(allIndexDefinitions())->toBe($before);
});

// ───────── Robustez de ensureIndex() (db-reviewer B1/B2) y parámetro withIndex ─────────

function ensureIndexProbeTable(): void
{
    $owner = DB::connection('pgsql_owner');

    $owner->statement('DROP TABLE IF EXISTS idx_probe_invalid CASCADE');
    $owner->statement('CREATE TABLE idx_probe_invalid (tenant_id bigint NOT NULL, col_id bigint NOT NULL)');
    // Duplicados: un UNIQUE CONCURRENTLY sobre ellos falla y deja un índice INVÁLIDO.
    $owner->statement('INSERT INTO idx_probe_invalid VALUES (1, 1), (1, 1)');
}

afterEach(function (): void {
    DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS idx_probe_invalid CASCADE');
});

test('#412 db-reviewer B1: un índice homónimo INVÁLIDO (creación concurrente fallida) se elimina y se recrea válido', function (): void {
    ensureIndexProbeTable();
    $owner = DB::connection('pgsql_owner');
    $name = TenantMigration::indexName('idx_probe_invalid', 'col_id');

    try {
        $owner->statement("CREATE UNIQUE INDEX CONCURRENTLY {$name} ON idx_probe_invalid (tenant_id, col_id)");
    } catch (Throwable) {
        // Esperado: 23505. Deja el índice inválido.
    }

    $state = $owner->selectOne('SELECT i.indisvalid AS valid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$name]);

    expect($state)->not->toBeNull()
        ->and($state->valid)->toBeFalse();

    indexMigration()->ensureIndex('idx_probe_invalid', 'col_id');

    $after = $owner->selectOne('SELECT i.indisvalid AS valid, i.indisunique AS uniq FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$name]);

    expect($after->valid)->toBeTrue()
        ->and($after->uniq)->toBeFalse();
});

test('#412 db-reviewer B2: un homónimo VÁLIDO con otra definición (parcial) no se acepta en silencio: la reverificación lanza', function (): void {
    ensureIndexProbeTable();
    $name = TenantMigration::indexName('idx_probe_invalid', 'col_id');

    DB::connection('pgsql_owner')->statement("CREATE INDEX {$name} ON idx_probe_invalid (tenant_id, col_id) WHERE col_id > 0");

    expect(fn () => indexMigration()->ensureIndex('idx_probe_invalid', 'col_id'))
        ->toThrow(RuntimeException::class, 'sigue sin un índice completo');
});

test('#412: ensureIndex() sobre una clave sin cobertura crea el del ayudante, y sobre una con índice completo propio no crea nada', function (): void {
    ensureIndexProbeTable();
    $owner = DB::connection('pgsql_owner');
    $name = TenantMigration::indexName('idx_probe_invalid', 'col_id');

    $owner->statement('CREATE INDEX idx_probe_manual ON idx_probe_invalid (tenant_id, col_id, tenant_id)');
    indexMigration()->ensureIndex('idx_probe_invalid', 'col_id');

    expect(allIndexDefinitions())->not->toHaveKey($name);

    $owner->statement('DROP INDEX idx_probe_manual');
    indexMigration()->ensureIndex('idx_probe_invalid', 'col_id');

    expect(allIndexDefinitions())->toHaveKey($name);
});
