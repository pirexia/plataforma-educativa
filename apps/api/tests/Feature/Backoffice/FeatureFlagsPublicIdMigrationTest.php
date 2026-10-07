<?php

use Illuminate\Support\Facades\DB;

/**
 * ADR-056 AR-05, CA-056-06, ADR-029 (paso 1.7b). La migración
 * `2026_10_07_100100_narrow_feature_flags_public_id_to_char26` lleva
 * `feature_flags.public_id` y `feature_flag_rules.public_id` de `text` a
 * `character(26)`. Estos tests verifican el esquema tras migrar y tras
 * revertir (`down()`), que el índice único de una sola columna y los
 * privilegios sobreviven, y que la comprobación previa de longitud aborta.
 *
 * Ejecutan `down()`/`up()` de la migración directamente sobre la base de
 * test y SIEMPRE la dejan migrada (`finally`).
 */
function ffPublicIdMigration(): object
{
    return require database_path('migrations/2026_10_07_100100_narrow_feature_flags_public_id_to_char26.php');
}

/**
 * @return array{type: string, notnull: bool, unique_single: int}
 */
function ffPublicIdShape(string $table): array
{
    $row = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
        SELECT format_type(a.atttypid, a.atttypmod) AS ft, a.attnotnull AS nn,
               (SELECT count(*) FROM pg_index i
                 WHERE i.indrelid = c.oid AND i.indisunique AND i.indnatts = 1 AND i.indkey[0] = a.attnum) AS uq
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
        WHERE c.relname = ? AND a.attname = 'public_id' AND NOT a.attisdropped
        SQL, [$table]);

    return ['type' => $row->ft, 'notnull' => (bool) $row->nn, 'unique_single' => (int) $row->uq];
}

/**
 * @return array<string, bool>
 */
function ffGrantSnapshot(): array
{
    $c = DB::connection('pgsql_owner');
    $has = fn (string $sql, array $b): bool => (bool) $c->selectOne($sql, $b)->p;

    return [
        'platform UPDATE status' => $has("SELECT has_column_privilege('plataforma_platform', 'feature_flags', 'status', 'UPDATE') AS p", []),
        'platform UPDATE key' => $has("SELECT has_column_privilege('plataforma_platform', 'feature_flags', 'key', 'UPDATE') AS p", []),
        'platform INSERT flags' => $has("SELECT has_table_privilege('plataforma_platform', 'feature_flags', 'INSERT') AS p", []),
        'app SELECT flags' => $has("SELECT has_table_privilege('plataforma_app', 'feature_flags', 'SELECT') AS p", []),
        'app INSERT flags' => $has("SELECT has_table_privilege('plataforma_app', 'feature_flags', 'INSERT') AS p", []),
        'app SELECT rules' => $has("SELECT has_table_privilege('plataforma_app', 'feature_flag_rules', 'SELECT') AS p", []),
        'app UPDATE rules' => $has("SELECT has_table_privilege('plataforma_app', 'feature_flag_rules', 'UPDATE') AS p", []),
    ];
}

test('CA-056-06: tras migrar, public_id de las dos tablas de feature flags es character(26) NOT NULL con índice único propio', function (): void {
    foreach (['feature_flags', 'feature_flag_rules'] as $table) {
        expect(ffPublicIdShape($table))->toBe(['type' => 'character(26)', 'notnull' => true, 'unique_single' => 1]);
    }
});

test('CA-056-06: down() restaura text y up() lo vuelve a character(26) sin perder NOT NULL, índice único ni privilegios', function (): void {
    $migration = ffPublicIdMigration();
    $grantsBefore = ffGrantSnapshot();

    expect($grantsBefore['platform UPDATE status'])->toBeTrue()
        ->and($grantsBefore['platform UPDATE key'])->toBeFalse()
        ->and($grantsBefore['platform INSERT flags'])->toBeFalse()
        ->and($grantsBefore['app SELECT flags'])->toBeTrue()
        ->and($grantsBefore['app INSERT flags'])->toBeFalse()
        ->and($grantsBefore['app UPDATE rules'])->toBeFalse();

    try {
        $migration->down();

        foreach (['feature_flags', 'feature_flag_rules'] as $table) {
            expect(ffPublicIdShape($table))->toBe(['type' => 'text', 'notnull' => true, 'unique_single' => 1]);
        }
        expect(ffGrantSnapshot())->toBe($grantsBefore);
    } finally {
        $migration->up();
    }

    foreach (['feature_flags', 'feature_flag_rules'] as $table) {
        expect(ffPublicIdShape($table))->toBe(['type' => 'character(26)', 'notnull' => true, 'unique_single' => 1]);
    }
    expect(ffGrantSnapshot())->toBe($grantsBefore);
});

test('CA-056-06: la migración aborta si alguna fila no tiene public_id de 26 caracteres', function (): void {
    $migration = ffPublicIdMigration();
    $owner = DB::connection('pgsql_owner');
    $key = 'test.migration.short_public_id';

    try {
        $migration->down();
        $owner->statement(
            "INSERT INTO feature_flags (public_id, key, name_key, description_key) VALUES ('corto', ?, 'x', 'y')",
            [$key],
        );

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'longitud distinta de 26');
    } finally {
        $owner->statement('DELETE FROM feature_flags WHERE key = ?', [$key]);
        $migration->up();
    }

    expect(ffPublicIdShape('feature_flags')['type'])->toBe('character(26)');
});
