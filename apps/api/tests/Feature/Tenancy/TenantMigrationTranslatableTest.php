<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// REQ-ACAD-001, 1.11-a: ayudantes de TenantMigration. CA-ACAD-014 (ADR-062
// §5.1: columna traducible con CHECK del motor) y CA-ACAD-038 (issue #407:
// tenantForeignId() crea el índice (tenant_id, columna)). INV-015.
//
// Tablas de prueba de test, nunca de producción; se crean una sola vez y no
// se borran (mismo motivo que TenantMigrationTest: DROP desde otra conexión
// espera el lock de la transacción de test).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function ensureTranslatableProbe(): void
{
    if (Schema::connection('pgsql_owner')->hasTable('translatable_probes')) {
        return;
    }

    TenantMigration::tenantTable('translatable_probes', function (Blueprint $table): void {
        TenantMigration::translatableColumn($table, 'name');
        $table->text('code')->nullable();
    });
}

/**
 * Inserta directamente en la tabla (sin pasar por Eloquent ni validación) y
 * devuelve el SQLSTATE si el motor la rechaza, o null si la acepta.
 */
function insertTranslatableProbe(string $json): ?string
{
    $tenant = Tenant::factory()->create();
    $state = null;

    app(TenantContext::class)->runFor($tenant->id, function () use ($json, &$state): void {
        try {
            // Savepoint: el rechazo del motor aborta la transacción del test.
            DB::transaction(fn () => DB::statement('INSERT INTO translatable_probes (name_translations) VALUES (?::jsonb)', [$json]));
        } catch (Throwable $e) {
            $state = $e->getCode();
        }
    });

    return $state === null ? null : (string) $state;
}

test('CA-ACAD-014 ADR-062 §5.1: la columna creada por el ayudante es jsonb NOT NULL', function (): void {
    ensureTranslatableProbe();

    $column = collect(Schema::connection('pgsql_owner')->getColumns('translatable_probes'))->firstWhere('name', 'name_translations');

    expect($column)->not->toBeNull()
        ->and($column['type_name'])->toBe('jsonb')
        ->and($column['nullable'])->toBeFalse();
});

test('CA-ACAD-014 ADR-062 §5.1: el motor rechaza {} con 23514 (check_violation)', function (): void {
    ensureTranslatableProbe();

    expect(insertTranslatableProbe('{}'))->toBe('23514');
});

test('CA-ACAD-014 ADR-062 §5.1: el motor rechaza una clave fuera del vocabulario («es») con 23514', function (): void {
    ensureTranslatableProbe();

    expect(insertTranslatableProbe('{"es": "Lengua"}'))->toBe('23514')
        ->and(insertTranslatableProbe('{"es-ES": "Lengua", "it": "Lingua"}'))->toBe('23514');
});

test('CA-ACAD-014 ADR-062 §5.1: el motor rechaza un valor que no es cadena con 23514', function (): void {
    ensureTranslatableProbe();

    expect(insertTranslatableProbe('{"es-ES": 1}'))->toBe('23514')
        ->and(insertTranslatableProbe('{"es-ES": null}'))->toBe('23514')
        ->and(insertTranslatableProbe('{"es-ES": {"a": "b"}}'))->toBe('23514')
        ->and(insertTranslatableProbe('{"es-ES": "ok", "en": ["x"]}'))->toBe('23514');
});

test('CA-ACAD-014 ADR-062 §5.1: el motor rechaza un JSON que no es objeto con 23514 (no con un error de operador)', function (): void {
    ensureTranslatableProbe();

    expect(insertTranslatableProbe('["es-ES"]'))->toBe('23514')
        ->and(insertTranslatableProbe('"Lengua"'))->toBe('23514')
        ->and(insertTranslatableProbe('null'))->toBe('23514')
        ->and(insertTranslatableProbe('7'))->toBe('23514');
});

test('CA-ACAD-014 ADR-062 §5.1: el motor acepta las cuatro claves del vocabulario con valores cadena', function (): void {
    ensureTranslatableProbe();

    expect(insertTranslatableProbe('{"es-ES": "Lengua", "en": "Language", "de": "Sprache", "fr": "Langue"}'))->toBeNull()
        ->and(insertTranslatableProbe('{"en": "Only English"}'))->toBeNull();
});

test('CA-ACAD-014 ADR-062 §5.1: el CHECK lo declara el ayudante con nombre acotado a 63 bytes, también con tabla y campo largos', function (): void {
    ensureTranslatableProbe();

    $name = TenantMigration::translationsCheckName('translatable_probes', 'name_translations');
    $exists = DB::connection('pgsql_owner')->selectOne(
        "SELECT 1 AS ok FROM pg_constraint WHERE conrelid = 'translatable_probes'::regclass AND contype = 'c' AND conname = ?",
        [$name],
    );

    expect($exists)->not->toBeNull();

    $long = TenantMigration::translationsCheckName('identity_provider_certificates_probe_table', 'description_long_translations');

    expect(strlen($long))->toBeLessThanOrEqual(63)
        ->and($long)->toEndWith('_check')
        ->and($long)->not->toBe(TenantMigration::translationsCheckName('identity_provider_certificates_probe_table', 'description_longer_translations'));

    if (! Schema::connection('pgsql_owner')->hasTable('long_name_translatable_probe_table_for_checks')) {
        TenantMigration::tenantTable('long_name_translatable_probe_table_for_checks', function (Blueprint $table): void {
            TenantMigration::translatableColumn($table, 'description_of_something_quite_long');
        });
    }

    $created = DB::connection('pgsql_owner')->selectOne(
        "SELECT conname FROM pg_constraint WHERE conrelid = 'long_name_translatable_probe_table_for_checks'::regclass AND contype = 'c'"
    );

    expect($created)->not->toBeNull()
        ->and(strlen($created->conname))->toBeLessThanOrEqual(63);
});

/**
 * Columnas del índice (en orden) de todos los índices no únicos de la tabla.
 *
 * @return array<string, list<string>> nombre del índice => columnas
 */
function indexColumnsOf(string $table): array
{
    $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT ic.relname AS index_name, a.attname AS column_name
        FROM pg_index i
        JOIN pg_class ic ON ic.oid = i.indexrelid
        JOIN LATERAL unnest(i.indkey::int2[]) WITH ORDINALITY AS k(attnum, ord) ON true
        JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum
        WHERE i.indrelid = ?::regclass AND NOT i.indisunique
        ORDER BY ic.relname, k.ord
        SQL, [$table]);

    $indexes = [];

    foreach ($rows as $row) {
        $indexes[$row->index_name][] = $row->column_name;
    }

    return $indexes;
}

test('CA-ACAD-038 issue #407: tenantForeignId() crea columna NOT NULL, FK compuesta e índice (tenant_id, columna)', function (): void {
    if (! Schema::connection('pgsql_owner')->hasTable('fk_index_parents')) {
        TenantMigration::tenantTable('fk_index_parents', function (Blueprint $table): void {
            $table->text('name');
        });
    }

    if (! Schema::connection('pgsql_owner')->hasTable('fk_index_children')) {
        TenantMigration::tenantTable('fk_index_children', function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'x_id', 'fk_index_parents');
            TenantMigration::tenantForeignId($table, 'y_id', 'fk_index_parents');
        });
    }

    $column = collect(Schema::connection('pgsql_owner')->getColumns('fk_index_children'))->firstWhere('name', 'x_id');
    $fks = collect(DB::connection('pgsql_owner')->select("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = 'fk_index_children'::regclass AND contype = 'f'"))->pluck('def');
    $indexes = indexColumnsOf('fk_index_children');

    expect($column['nullable'])->toBeFalse()
        ->and($fks->contains(fn (string $def): bool => str_contains($def, 'FOREIGN KEY (tenant_id, x_id) REFERENCES fk_index_parents(tenant_id, id)')))->toBeTrue()
        ->and(array_values($indexes))->toContain(['tenant_id', 'x_id'])
        ->and(array_values($indexes))->toContain(['tenant_id', 'y_id']);
});

test('CA-ACAD-038 issue #407: el nombre del índice cabe en 63 bytes, sin truncado silencioso, con tabla y columna largas', function (): void {
    expect(TenantMigration::indexName('subjects', 'course_id'))->toBe('subjects_course_id_tenant_idx');

    $long = TenantMigration::indexName('identity_provider_certificates_probe_table', 'a_really_long_reference_column_id');

    expect(strlen($long))->toBeLessThanOrEqual(63)
        ->and($long)->toEndWith('_tenant_idx')
        ->and($long)->not->toBe(TenantMigration::indexName('identity_provider_certificates_probe_table', 'a_really_long_reference_column_ib'));

    if (! Schema::connection('pgsql_owner')->hasTable('fk_index_long_parent_probe_table')) {
        TenantMigration::tenantTable('fk_index_long_parent_probe_table', function (Blueprint $table): void {
            $table->text('name');
        });
    }

    if (! Schema::connection('pgsql_owner')->hasTable('fk_index_long_child_probe_table_name')) {
        TenantMigration::tenantTable('fk_index_long_child_probe_table_name', function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'a_really_long_reference_column_id', 'fk_index_long_parent_probe_table', 'fk_idx_long_child_fk');
        });
    }

    $expected = TenantMigration::indexName('fk_index_long_child_probe_table_name', 'a_really_long_reference_column_id');
    $indexes = indexColumnsOf('fk_index_long_child_probe_table_name');

    expect($indexes)->toHaveKey($expected)
        ->and($indexes[$expected])->toBe(['tenant_id', 'a_really_long_reference_column_id']);
});
