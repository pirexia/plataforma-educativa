<?php

use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProbeTables;

// REQ-ACAD-001, 1.11-a: ayudantes de TenantMigration. CA-ACAD-014 (ADR-062
// §5.1: columna traducible con CHECK del motor) y CA-ACAD-038 (issue #407:
// tenantForeignId() crea el índice (tenant_id, columna)). INV-015.
//
// Tablas de prueba de test, nunca de producción; se crean una sola vez y no
// se borran (mismo motivo que TenantMigrationTest: DROP desde otra conexión
// espera el lock de la transacción de test).

// Las tablas de este fichero se borran siempre y se comprueba que no queda
// ninguna (Tests\Support\ProbeTables): la causa de las huérfanas era que el
// propietario crea y altera fuera de la transacción del test.
function translatableTestTables(): array
{
    return [
        'translatable_probes', 'long_name_translatable_probe_table_for_checks',
        'fk_index_children', 'fk_index_parents', 'fk_index_long_child_probe_table_name', 'fk_index_long_parent_probe_table',
        'translatable_append_only_probe', 'translatable_two_columns_probe',
        'translatable_failure_probe', 'translatable_wrong_table_probe', 'translatable_closure_failure', 'fk_index_twice_probe',
        'cx_a_b', 'cx_a', 'cx_parent',
    ];
}

beforeEach(function (): void {
    $this->tablesBefore = ProbeTables::snapshot();
});

afterEach(function (): void {
    $leftovers = ProbeTables::dropAndReportLeftovers(translatableTestTables(), $this->tablesBefore);

    DB::connection('pgsql_platform')->table('tenants')->delete();

    expect($leftovers)->toBe([], 'el test deja tablas en la base de test');
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
function insertTranslatableProbe(string $json, string $table = 'translatable_probes', string $column = 'name_translations'): ?string
{
    $tenant = Tenant::factory()->create();
    $state = null;

    app(TenantContext::class)->runFor($tenant->id, function () use ($json, $table, $column, &$state): void {
        try {
            // Savepoint: el rechazo del motor aborta la transacción del test.
            DB::transaction(fn () => DB::statement("INSERT INTO {$table} ({$column}) VALUES (?::jsonb)", [$json]));
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
    expect(TenantMigration::indexName('subjects', 'course_id'))->toBe('subjects__course_id_tenant_idx');

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

/** Blueprint suelto (sin ejecutar) sobre la conexión del propietario, con su gramática ya resuelta. */
function bareBlueprint(string $table): Blueprint
{
    return new Blueprint(Schema::connection('pgsql_owner')->getConnection(), $table);
}

// ───────── Revisión de db-reviewer (1.11-a): estado de creación y casos límite ─────────

test('CA-ACAD-014 (db-reviewer Media 2): translatableColumn() fuera de tenantTable()/tenantTableAppendOnly() lanza; nunca omite el CHECK en silencio', function (): void {
    $blueprint = bareBlueprint('una_tabla_cualquiera');

    expect(fn () => TenantMigration::translatableColumn($blueprint, 'name'))->toThrow(LogicException::class, 'tenantTable()');
});

test('CA-ACAD-014 (db-reviewer Media 2): dentro de una creación, solo vale para la tabla que se está creando', function (): void {
    $other = bareBlueprint('otra_tabla_distinta');

    expect(fn () => TenantMigration::tenantTable('translatable_wrong_table_probe', function (Blueprint $table) use ($other): void {
        $table->text('x');
        TenantMigration::translatableColumn($other, 'name');
    }))->toThrow(LogicException::class);

    expect(Schema::connection('pgsql_owner')->hasTable('translatable_wrong_table_probe'))->toBeFalse();
});

test('CA-ACAD-014 (db-reviewer Baja 3): si el create falla no queda estado: ni tabla ni CHECK pendiente que se aplique a una creación posterior', function (): void {
    Schema::connection('pgsql_owner')->dropIfExists('translatable_failure_probe');

    // Falla al crear (FK a una tabla inexistente) después de registrar la columna.
    expect(fn () => TenantMigration::tenantTable('translatable_failure_probe', function (Blueprint $table): void {
        TenantMigration::translatableColumn($table, 'name');
        TenantMigration::tenantForeignId($table, 'missing_id', 'tabla_que_no_existe_nunca');
    }))->toThrow(QueryException::class);

    // El `create` ya se había ejecutado cuando falla la FK (fuera de la transacción de una migración real, que lo revertiría).
    Schema::connection('pgsql_owner')->dropIfExists('translatable_failure_probe');

    // Tampoco queda «una creación en curso»: la llamada suelta vuelve a lanzar.
    $blueprint = bareBlueprint('translatable_failure_probe');

    expect(fn () => TenantMigration::translatableColumn($blueprint, 'name'))->toThrow(LogicException::class);

    // Y una creación posterior de la misma tabla, sin columna traducible, no hereda el CHECK huérfano.
    TenantMigration::tenantTable('translatable_failure_probe', function (Blueprint $table): void {
        $table->text('name_translations');
    });

    $checks = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_constraint WHERE conrelid = 'translatable_failure_probe'::regclass AND contype = 'c'")->n;

    expect((int) $checks)->toBe(0);

    Schema::connection('pgsql_owner')->dropIfExists('translatable_failure_probe');
});

test('CA-ACAD-014 (db-reviewer Baja 3): una excepción lanzada por el cierre de columnas también limpia el estado', function (): void {
    expect(fn () => TenantMigration::tenantTableAppendOnly('translatable_closure_failure', function (Blueprint $table): void {
        TenantMigration::translatableColumn($table, 'name');

        throw new RuntimeException('fallo del cierre');
    }))->toThrow(RuntimeException::class, 'fallo del cierre');

    $blueprint = bareBlueprint('translatable_closure_failure');

    expect(fn () => TenantMigration::translatableColumn($blueprint, 'name'))->toThrow(LogicException::class);
});

test('CA-ACAD-014 (db-reviewer Baja 5): translatableColumn() funciona dentro de tenantTableAppendOnly() y el CHECK muerde', function (): void {
    if (! Schema::connection('pgsql_owner')->hasTable('translatable_append_only_probe')) {
        TenantMigration::tenantTableAppendOnly('translatable_append_only_probe', function (Blueprint $table): void {
            TenantMigration::translatableColumn($table, 'label');
        });
    }

    expect(insertTranslatableProbe('{"es": "x"}', 'translatable_append_only_probe', 'label_translations'))->toBe('23514')
        ->and(insertTranslatableProbe('{"es-ES": "x"}', 'translatable_append_only_probe', 'label_translations'))->toBeNull();
});

function insertTwoColumnsProbe(string $name, string $description): ?string
{
    $tenant = Tenant::factory()->create();
    $state = null;

    app(TenantContext::class)->runFor($tenant->id, function () use ($name, $description, &$state): void {
        try {
            DB::transaction(fn () => DB::statement('INSERT INTO translatable_two_columns_probe (name_translations, description_translations) VALUES (?::jsonb, ?::jsonb)', [$name, $description]));
        } catch (Throwable $e) {
            $state = (string) $e->getCode();
        }
    });

    return $state;
}

test('CA-ACAD-014 (db-reviewer Baja 5): dos columnas traducibles en una tabla tienen cada una su CHECK', function (): void {
    if (! Schema::connection('pgsql_owner')->hasTable('translatable_two_columns_probe')) {
        TenantMigration::tenantTable('translatable_two_columns_probe', function (Blueprint $table): void {
            TenantMigration::translatableColumn($table, 'name');
            TenantMigration::translatableColumn($table, 'description');
        });
    }

    $names = collect(DB::connection('pgsql_owner')->select("SELECT conname FROM pg_constraint WHERE conrelid = 'translatable_two_columns_probe'::regclass AND contype = 'c'"))->pluck('conname')->all();

    expect($names)->toContain(TenantMigration::translationsCheckName('translatable_two_columns_probe', 'name_translations'))
        ->and($names)->toContain(TenantMigration::translationsCheckName('translatable_two_columns_probe', 'description_translations'))
        ->and(insertTwoColumnsProbe('{"es-ES": "ok"}', '{"xx": "x"}'))->toBe('23514')
        ->and(insertTwoColumnsProbe('{"xx": "x"}', '{"es-ES": "ok"}'))->toBe('23514')
        ->and(insertTwoColumnsProbe('{"es-ES": "ok"}', '{"en": "ok"}'))->toBeNull();
});

test('CA-ACAD-014 (db-reviewer Baja 5): las claves con otra grafía («es_ES», «ES-es», «es-es») las rechaza el motor: el vocabulario distingue mayúsculas y guion', function (): void {
    ensureTranslatableProbe();

    foreach (['{"es_ES": "x"}', '{"ES-es": "x"}', '{"es-es": "x"}', '{"EN": "x"}', '{"es-ES ": "x"}'] as $json) {
        expect(insertTranslatableProbe($json))->toBe('23514', $json);
    }
});

test('CA-ACAD-014 ADR-062 §5.1 (db-reviewer Baja 6): el motor NO garantiza cadenas no vacías, lo hace el validador de servidor', function (): void {
    ensureTranslatableProbe();

    // Hecho documentado, no un deseo: el CHECK de §5.1 tiene cuatro condiciones y
    // «no vacío» no es una; lo exige TranslationsValidator (§5.2, translations.empty_value).
    expect(insertTranslatableProbe('{"en": ""}'))->toBeNull()
        ->and(insertTranslatableProbe('{"en": "   "}'))->toBeNull();
});

test('CA-ACAD-038 (db-reviewer Baja 5): tenantForeignId() dos veces con la misma columna falla al crear y no deja tabla a medias', function (): void {
    if (! Schema::connection('pgsql_owner')->hasTable('fk_index_parents')) {
        TenantMigration::tenantTable('fk_index_parents', function (Blueprint $table): void {
            $table->text('name');
        });
    }

    Schema::connection('pgsql_owner')->dropIfExists('fk_index_twice_probe');

    expect(fn () => TenantMigration::tenantTable('fk_index_twice_probe', function (Blueprint $table): void {
        TenantMigration::tenantForeignId($table, 'x_id', 'fk_index_parents');
        TenantMigration::tenantForeignId($table, 'x_id', 'fk_index_parents');
    }))->toThrow(QueryException::class, 'specified more than once');

    expect(Schema::connection('pgsql_owner')->hasTable('fk_index_twice_probe'))->toBeFalse();
});

test('CA-ACAD-038 issue #415: (a_b, c_id) y (a, b_c_id) dan nombres de índice y de CHECK distintos', function (): void {
    expect(TenantMigration::indexName('a_b', 'c_id'))->toBe('a_b__c_id_tenant_idx')
        ->and(TenantMigration::indexName('a', 'b_c_id'))->toBe('a__b_c_id_tenant_idx')
        ->and(TenantMigration::indexName('a_b', 'c_id'))->not->toBe(TenantMigration::indexName('a', 'b_c_id'))
        ->and(TenantMigration::translationsCheckName('a_b', 'c_translations'))->not->toBe(TenantMigration::translationsCheckName('a', 'b_c_translations'));
});

test('CA-ACAD-038 issue #415: los dos pares colisionantes pueden coexistir en el mismo esquema (los índices se crean de verdad)', function (): void {
    foreach (['cx_a_b' => 'c_id', 'cx_a' => 'b_c_id'] as $table => $column) {
        Schema::connection('pgsql_owner')->dropIfExists($table);
    }

    TenantMigration::tenantTable('cx_parent', function (Blueprint $table): void {
        $table->text('name');
    });
    TenantMigration::tenantTable('cx_a_b', function (Blueprint $table): void {
        TenantMigration::tenantForeignId($table, 'c_id', 'cx_parent');
    });
    TenantMigration::tenantTable('cx_a', function (Blueprint $table): void {
        TenantMigration::tenantForeignId($table, 'b_c_id', 'cx_parent');
    });

    expect(indexColumnsOf('cx_a_b'))->toHaveKey(TenantMigration::indexName('cx_a_b', 'c_id'))
        ->and(indexColumnsOf('cx_a'))->toHaveKey(TenantMigration::indexName('cx_a', 'b_c_id'));
});

test('CA-ACAD-038 issue #415: el nombre con hash sigue en 63 bytes y distingue pares que solo difieren en la frontera tabla/columna', function (): void {
    $one = TenantMigration::indexName('identity_provider_certificates_probe_ab', 'c_reference_column_long_id');
    $two = TenantMigration::indexName('identity_provider_certificates_probe_a', 'b_c_reference_column_long_id');

    expect(strlen($one))->toBeLessThanOrEqual(63)
        ->and(strlen($two))->toBeLessThanOrEqual(63)
        ->and($one)->not->toBe($two);
});
