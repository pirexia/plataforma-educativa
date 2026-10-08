<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AcademicYearProbe;

pest()->group('arch');

// ADR-057 §5.3 AR-13 (CA-CURSO-043, CA-057-06, RN-CURSO-23, INV-015), sobre
// el ESQUEMA REAL (`pg_catalog`: pg_attribute + pg_trigger + pg_proc), la
// técnica que ADR-056 §3.1 prefiere porque «ve la verdad», como AR-04/AR-05.
// Toda tabla del esquema `public` (ordinaria o particionada) con columna
// `academic_year_id` tiene un disparador HABILITADO llamado
// `academic_year_write_guard`, BEFORE, FOR EACH ROW, sobre INSERT, UPDATE y
// DELETE, que ejecuta `app.assert_academic_year_writable()`. Las particiones
// heredan el disparador del padre (clonado, `tgparentid`).

/**
 * Excepciones de AR-13: lista CERRADA y nominal (ADR-056 §3.2, ADR-057
 * §5.3). **Vacía al nacer**; ampliarla exige especificación aprobada
 * expresamente por el usuario. Una tabla sin el disparador sería una tabla
 * de curso escribible para siempre: no se prevé ninguna.
 *
 * @return array<string, string> tabla => motivo
 */
function academicYearGuardExceptions(): array
{
    return [];
}

/**
 * Tablas del esquema con columna `academic_year_id`.
 *
 * @return list<string>
 */
function tablesWithAcademicYearColumn(string $schema, string $tablePrefix = ''): array
{
    $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.relname AS tabla
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid AND c.relkind IN ('r', 'p')
        JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = ?
        WHERE a.attname = 'academic_year_id' AND NOT a.attisdropped AND c.relname LIKE ?
        ORDER BY c.relname
        SQL, [$schema, $tablePrefix.'%']);

    return array_map(static fn (object $r): string => $r->tabla, $rows);
}

/**
 * Tablas con la columna cuyo disparador de guarda falta o no cumple la forma
 * exacta de la regla.
 *
 * @return list<string>
 */
function tablesMissingAcademicYearGuard(string $schema, string $tablePrefix = ''): array
{
    $missing = [];

    foreach (tablesWithAcademicYearColumn($schema, $tablePrefix) as $table) {
        $ok = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
            SELECT count(*) AS n
            FROM pg_trigger t
            JOIN pg_class c ON c.oid = t.tgrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = ?
            JOIN pg_proc p ON p.oid = t.tgfoid AND p.proname = 'assert_academic_year_writable'
            JOIN pg_namespace pn ON pn.oid = p.pronamespace AND pn.nspname = 'app'
            WHERE c.relname = ?
              AND NOT t.tgisinternal
              AND t.tgname = 'academic_year_write_guard'
              AND t.tgenabled <> 'D'
              -- tgtype: ROW=1, BEFORE=2, INSERT=4, DELETE=8, UPDATE=16, TRUNCATE=32, INSTEAD=64
              AND (t.tgtype & 31) = 31
              AND (t.tgtype & (32 | 64)) = 0
            SQL, [$schema, $table])->n;

        if ((int) $ok < 1) {
            $missing[] = $table;
        }
    }

    return $missing;
}

test('AR-13 CA-CURSO-043 CA-057-06 RN-CURSO-23 ADR-057 §5.3: toda tabla con academic_year_id lleva el disparador academic_year_write_guard habilitado', function (): void {
    // Garantiza la tabla sonda creada por el ayudante: el esquema real aún
    // no tiene ninguna tabla de negocio con la columna (1.10), y la regla no
    // puede pasar en vacío (aserción de no vacuidad sobre la unión).
    AcademicYearProbe::ensureTable();

    $tables = tablesWithAcademicYearColumn('public');

    expect($tables)->not->toBeEmpty('AR-13 pasaría en vacío: ninguna tabla con academic_year_id')
        ->and($tables)->toContain(AcademicYearProbe::TABLE);

    $missing = array_values(array_diff(
        tablesMissingAcademicYearGuard('public'),
        array_keys(academicYearGuardExceptions()),
    ));

    expect($missing)->toBe([], "tablas con academic_year_id sin el disparador academic_year_write_guard (RN-CURSO-23, ADR-057 §5.3): usa TenantMigration::tenantTable()/tenantTableAppendOnly() o guardAcademicYearWrites():\n".implode("\n", $missing));
});

test('AR-13 CA-057-06 ADR-057 §5.3: la lista de excepciones está vacía y solo puede reducirse', function (): void {
    // Ampliarla exige especificación aprobada expresamente por el usuario.
    expect(academicYearGuardExceptions())->toBe([]);
});

test('AR-13 CA-CURSO-043 CA-057-06 control negativo: detecta tabla sin disparador, deshabilitado, incompleto, de otra función o de otro nombre; acepta los ayudantes', function (): void {
    // DDL transaccional de PostgreSQL: las tablas de control viven solo
    // dentro de esta transacción de `pgsql_owner` y se revierten siempre.
    $owner = DB::connection('pgsql_owner');
    $p = 'arch_control_ay_';
    $fn = 'app.assert_academic_year_writable()';

    $owner->beginTransaction();

    try {
        $plain = static fn (string $name) => $owner->statement("CREATE TABLE {$p}{$name} (id bigint PRIMARY KEY, tenant_id bigint, academic_year_id bigint NOT NULL)");

        // Sin ayudante y sin disparador (la sonda 2 de funcional.md §1.3).
        $plain('sin_disparador');

        // Con disparador correcto pero deshabilitado.
        $plain('deshabilitado');
        $owner->statement("CREATE TRIGGER academic_year_write_guard BEFORE INSERT OR UPDATE OR DELETE ON {$p}deshabilitado FOR EACH ROW EXECUTE FUNCTION {$fn}");
        $owner->statement("ALTER TABLE {$p}deshabilitado DISABLE TRIGGER academic_year_write_guard");

        // Disparador que no cubre los tres eventos.
        $plain('solo_insert');
        $owner->statement("CREATE TRIGGER academic_year_write_guard BEFORE INSERT ON {$p}solo_insert FOR EACH ROW EXECUTE FUNCTION {$fn}");

        // AFTER en vez de BEFORE.
        $plain('despues');
        $owner->statement("CREATE TRIGGER academic_year_write_guard AFTER INSERT OR UPDATE OR DELETE ON {$p}despues FOR EACH ROW EXECUTE FUNCTION {$fn}");

        // Por sentencia en vez de por fila.
        $plain('por_sentencia');
        $owner->statement("CREATE TRIGGER academic_year_write_guard BEFORE INSERT OR UPDATE OR DELETE ON {$p}por_sentencia FOR EACH STATEMENT EXECUTE FUNCTION {$fn}");

        // Mismo nombre, otra función.
        $owner->statement('CREATE FUNCTION app.arch_control_ay_otra() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END $$');
        $plain('otra_funcion');
        $owner->statement("CREATE TRIGGER academic_year_write_guard BEFORE INSERT OR UPDATE OR DELETE ON {$p}otra_funcion FOR EACH ROW EXECUTE FUNCTION app.arch_control_ay_otra()");

        // Función correcta, otro nombre de disparador.
        $plain('otro_nombre');
        $owner->statement("CREATE TRIGGER guarda_curso BEFORE INSERT OR UPDATE OR DELETE ON {$p}otro_nombre FOR EACH ROW EXECUTE FUNCTION {$fn}");

        // Correcta, a mano (control positivo del SQL).
        $plain('a_mano_ok');
        $owner->statement("CREATE TRIGGER academic_year_write_guard BEFORE INSERT OR UPDATE OR DELETE ON {$p}a_mano_ok FOR EACH ROW EXECUTE FUNCTION {$fn}");

        // Las tres vías del ayudante de TenantMigration.
        TenantMigration::tenantTable("{$p}helper", function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
        });
        TenantMigration::tenantTableAppendOnly("{$p}append_only", function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
        });
        TenantMigration::tenantTable("{$p}tardia", function (Blueprint $table): void {
            $table->text('name');
        });
        Schema::connection('pgsql_owner')->table("{$p}tardia", function (Blueprint $table): void {
            $table->unsignedBigInteger('academic_year_id')->nullable();
        });
        TenantMigration::guardAcademicYearWrites("{$p}tardia");

        // Una tabla sin la columna no es de curso: la regla no la toca.
        TenantMigration::tenantTable("{$p}sin_columna", function (Blueprint $table): void {
            $table->text('name');
        });

        expect(tablesWithAcademicYearColumn('public', $p))->toBe([
            $p.'a_mano_ok', $p.'append_only', $p.'deshabilitado', $p.'despues', $p.'helper', $p.'otra_funcion',
            $p.'otro_nombre', $p.'por_sentencia', $p.'sin_disparador', $p.'solo_insert', $p.'tardia',
        ])->and(tablesMissingAcademicYearGuard('public', $p))->toBe([
            $p.'deshabilitado', $p.'despues', $p.'otra_funcion', $p.'otro_nombre', $p.'por_sentencia', $p.'sin_disparador', $p.'solo_insert',
        ]);
    } finally {
        $owner->rollBack();
    }
});

/**
 * Tablas con el disparador `academic_year_write_guard` (o cualquiera que
 * ejecute `app.assert_academic_year_writable()`) que NO tienen la columna
 * `academic_year_id`: el disparador leería `NEW.academic_year_id` y fallaría
 * en cada escritura (db-reviewer M-2). Típico de un `DROP COLUMN` sin
 * `DROP TRIGGER` previo.
 *
 * @return list<string>
 */
function tablesWithGuardButNoAcademicYearColumn(string $schema, string $tablePrefix = ''): array
{
    $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT DISTINCT c.relname AS tabla
        FROM pg_trigger t
        JOIN pg_class c ON c.oid = t.tgrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = ?
        JOIN pg_proc p ON p.oid = t.tgfoid AND p.proname = 'assert_academic_year_writable'
        JOIN pg_namespace pn ON pn.oid = p.pronamespace AND pn.nspname = 'app'
        WHERE NOT t.tgisinternal
          AND c.relname LIKE ?
          AND NOT EXISTS (
              SELECT 1 FROM pg_attribute a
              WHERE a.attrelid = c.oid AND a.attname = 'academic_year_id' AND NOT a.attisdropped
          )
        ORDER BY c.relname
        SQL, [$schema, $tablePrefix.'%']);

    return array_map(static fn (object $r): string => $r->tabla, $rows);
}

test('AR-13 CA-057-06 db-reviewer M-2: ninguna tabla con el disparador carece de la columna academic_year_id', function (): void {
    AcademicYearProbe::ensureTable();

    expect(tablesWithGuardButNoAcademicYearColumn('public'))->toBe([], 'tablas con el disparador pero sin academic_year_id: haz DROP TRIGGER academic_year_write_guard antes de DROP COLUMN academic_year_id');
});

test('AR-13 CA-057-06 M-2 control negativo: detecta el disparador que sobrevive a DROP COLUMN academic_year_id', function (): void {
    $owner = DB::connection('pgsql_owner');
    $p = 'arch_control_ay_huerfano_';

    $owner->beginTransaction();

    try {
        TenantMigration::tenantTable("{$p}x", function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
        });
        $owner->statement("ALTER TABLE {$p}x DROP COLUMN academic_year_id CASCADE");

        expect(tablesWithGuardButNoAcademicYearColumn('public', $p))->toBe([$p.'x']);

        // Orden correcto: DROP TRIGGER antes de DROP COLUMN.
        $owner->statement("DROP TRIGGER academic_year_write_guard ON {$p}x");

        expect(tablesWithGuardButNoAcademicYearColumn('public', $p))->toBe([]);
    } finally {
        $owner->rollBack();
    }
});

test('AR-13 CA-057-10 db-reviewer B-6: ningún rol de aplicación puede crear objetos en el esquema app', function (): void {
    // Quien pudiera crear en `app` podría sombrear la función de guarda.
    foreach (['plataforma_app', 'plataforma_platform'] as $role) {
        $row = DB::connection('pgsql_owner')->selectOne("SELECT pg_catalog.has_schema_privilege(?, 'app', 'CREATE') AS ok", [$role]);

        expect($row->ok)->toBeFalse("{$role} tiene CREATE sobre el esquema app");
    }
});

test('RN-CURSO-23 db-reviewer M-1: la migración de la función detalla el comando exacto si falta CREATE sobre app', function (): void {
    $migration = require database_path('migrations/2026_10_07_100200_create_academic_year_write_guard_function.php');
    $message = $migration::missingCreatePrivilegeMessage();

    expect($message)->toContain('GRANT CREATE ON SCHEMA app TO')
        ->and($message)->toContain('plataforma_owner')
        ->and($message)->toContain('RUNBOOK.md');

    // El propietario del entorno de test sí lo tiene (la comprobación previa pasa).
    $row = DB::connection('pgsql_owner')->selectOne("SELECT pg_catalog.has_schema_privilege(current_user, 'app', 'CREATE') AS ok");
    expect($row->ok)->toBeTrue();
});

test('RN-CURSO-23 #385: guardAcademicYearWrites() dice con claridad que el disparador ya existe y no deja lock_timeout cambiado', function (): void {
    $owner = DB::connection('pgsql_owner');
    $p = 'arch_control_ay_idem_';

    $owner->beginTransaction();

    try {
        $before = $owner->selectOne("SELECT current_setting('lock_timeout') AS v")->v;

        // Tabla con la columna añadida a posteriori: primera llamada correcta.
        TenantMigration::tenantTable("{$p}t", function (Blueprint $table): void {
            $table->text('name');
        });
        Schema::connection('pgsql_owner')->table("{$p}t", function (Blueprint $table): void {
            $table->unsignedBigInteger('academic_year_id')->nullable();
        });
        TenantMigration::guardAcademicYearWrites("{$p}t");

        expect($owner->selectOne("SELECT current_setting('lock_timeout') AS v")->v)->toBe($before, 'el tope de bloqueo no se restaura');

        // Segunda llamada: error explícito de producto, no el genérico de PostgreSQL.
        expect(fn () => TenantMigration::guardAcademicYearWrites("{$p}t"))
            ->toThrow(RuntimeException::class, TenantMigration::guardAlreadyExistsMessage("{$p}t"));

        // Una tabla creada con el ayudante con la columna ya trae el disparador: llamar a mano sobra y lo dice.
        TenantMigration::tenantTable("{$p}u", function (Blueprint $table): void {
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
        });
        expect(fn () => TenantMigration::guardAcademicYearWrites("{$p}u"))
            ->toThrow(RuntimeException::class, 'ya tiene el disparador academic_year_write_guard');
    } finally {
        $owner->rollBack();
    }
});

/**
 * Excepciones de la comprobación referencial de AR-13 (security-reviewer M-1):
 * lista CERRADA y nominal `tabla => motivo`, **vacía al nacer**; solo puede
 * reducirse y ampliarla exige especificación aprobada expresamente por el
 * usuario (ADR-056 §3.2).
 *
 * @return array<string, string>
 */
function referentialActionExceptions(): array
{
    return [];
}

/**
 * Claves foráneas de tablas con `academic_year_id` cuya acción referencial
 * (ON DELETE o ON UPDATE) no es NO ACTION/RESTRICT: CASCADE, SET NULL o SET
 * DEFAULT. PostgreSQL ejecuta esas acciones con los privilegios del
 * PROPIETARIO de la tabla hija, que está exento del disparador
 * `academic_year_write_guard` (ADR-057 §5.2): saltarían el bloqueo de un curso
 * cerrado sin que el disparador lo vea.
 *
 * @return list<string> `tabla.restricción`
 */
function referentialActionViolations(string $schema, string $tablePrefix = ''): array
{
    $violations = [];

    foreach (tablesWithAcademicYearColumn($schema, $tablePrefix) as $table) {
        $rows = DB::connection('pgsql_owner')->select(<<<'SQL'
            SELECT con.conname AS nombre
            FROM pg_constraint con
            JOIN pg_class c ON c.oid = con.conrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = ?
            WHERE con.contype = 'f'
              AND c.relname = ?
              AND (con.confdeltype IN ('c', 'n', 'd') OR con.confupdtype IN ('c', 'n', 'd'))
            ORDER BY con.conname
            SQL, [$schema, $table]);

        foreach ($rows as $row) {
            $violations[] = "{$table}.{$row->nombre}";
        }
    }

    return $violations;
}

test('AR-13 CA-057-06 security-reviewer M-1 ADR-057 §5.2: ninguna FK de una tabla con academic_year_id tiene acción referencial CASCADE, SET NULL ni SET DEFAULT (corren como propietario y saltan el disparador)', function (): void {
    AcademicYearProbe::ensureTable();

    $exceptions = array_keys(referentialActionExceptions());
    $violations = array_values(array_filter(
        referentialActionViolations('public'),
        static fn (string $v): bool => ! in_array(explode('.', $v, 2)[0], $exceptions, true),
    ));

    expect($violations)->toBe([], "claves foráneas con ON DELETE/ON UPDATE CASCADE, SET NULL o SET DEFAULT en tablas con academic_year_id (saltan el disparador: la acción corre como propietario, ADR-057 §5.2). Usa NO ACTION (sin cascadeOnDelete/nullOnDelete/cascadeOnUpdate):\n".implode("\n", $violations));
});

test('AR-13 CA-057-06 M-1: la lista de excepciones referenciales está vacía y solo puede reducirse', function (): void {
    expect(referentialActionExceptions())->toBe([]);
});

test('AR-13 CA-057-06 M-1 control negativo: detecta CASCADE, SET NULL y ON UPDATE CASCADE; no detecta NO ACTION', function (): void {
    $owner = DB::connection('pgsql_owner');
    $p = 'arch_control_ay_fk_';

    $owner->beginTransaction();

    try {
        $owner->statement("CREATE TABLE {$p}padre (id bigint PRIMARY KEY)");

        foreach ([
            'cascade' => 'ON DELETE CASCADE',
            'set_null' => 'ON DELETE SET NULL',
            'upd_cascade' => 'ON UPDATE CASCADE',
            'no_action' => 'ON DELETE NO ACTION ON UPDATE NO ACTION',
        ] as $name => $action) {
            $owner->statement("CREATE TABLE {$p}{$name} (id bigint PRIMARY KEY, academic_year_id bigint, padre_id bigint REFERENCES {$p}padre(id) {$action})");
        }

        expect(referentialActionViolations('public', $p))->toBe([
            "{$p}cascade.{$p}cascade_padre_id_fkey",
            "{$p}set_null.{$p}set_null_padre_id_fkey",
            "{$p}upd_cascade.{$p}upd_cascade_padre_id_fkey",
        ]);
    } finally {
        $owner->rollBack();
    }
});

test('RN-CURSO-23 #385 B-2: guardAcademicYearWrites() exige transacción de pgsql_owner y lo dice', function (): void {
    $owner = DB::connection('pgsql_owner');

    // Fuera de transacción el tope local no protegería el CREATE TRIGGER.
    while ($owner->transactionLevel() > 0) {
        $owner->rollBack();
    }

    expect(fn () => TenantMigration::guardAcademicYearWrites('arch_control_ay_sin_tx'))
        ->toThrow(RuntimeException::class, TenantMigration::guardRequiresTransactionMessage('arch_control_ay_sin_tx'));
});
