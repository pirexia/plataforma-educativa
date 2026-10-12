<?php

namespace App\Support\Tenancy;

use App\Support\I18n\Locales;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * ADR-033 §5, §6, §9 y ADR-034 §6, §7: lo que toda tabla de negocio necesita,
 * en un solo sitio, para no depender de que cada migración se acuerde de las
 * cuatro cosas por separado.
 *
 * Va explícitamente por la conexión pgsql_owner: no asume que la conexión
 * ambiente ya sea el propietario (cierto dentro de una migración real
 * ejecutada con --database=pgsql_owner, falso en cualquier otro contexto
 * que reutilice este helper, como un fixture de test).
 */
final class TenantMigration
{
    /**
     * Tabla de tenant ordinaria: id, tenant_id (DEFAULT+RLS), columnas
     * propias, autoría, timestamps, borrado lógico y UNIQUE (tenant_id, id).
     *
     * created_by/updated_by (ADR-034 §6) se añaden siempre como columnas
     * nullable — nullable porque hay actores sin usuario: consola, jobs del
     * sistema, seeders, importaciones. Su clave foránea compuesta a `users`
     * solo se añade aquí si `users` ya existe en el momento de crear la
     * tabla (cierto desde 0.8.5 en adelante); las dos tablas creadas antes
     * de que `users` exista (`academic_years`, `people`, 0.8.2/0.8.3) la
     * reciben más tarde con addAuthorshipForeignKeys(), llamado desde la
     * migración de 0.8.4 justo después de crear `users`.
     */
    public static function tenantTable(string $table, Closure $columns): void
    {
        self::assertSafeIdentifier($table);

        self::$tableInProgress = $table;

        try {
            Schema::connection('pgsql_owner')->create($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->id();
                $blueprint->foreignId('tenant_id');

                $columns($blueprint);

                $blueprint->unsignedBigInteger('created_by')->nullable();
                $blueprint->unsignedBigInteger('updated_by')->nullable();

                $blueprint->timestampsTz();
                $blueprint->softDeletesTz();

                // Clave foránea compuesta (ADR-033 §6): RLS no protege la
                // integridad referencial, la comprobación de un FK la ejecuta
                // el sistema saltándose las políticas. Sin este índice único,
                // un hijo no puede declarar FOREIGN KEY (tenant_id, padre_id)
                // REFERENCES padre (tenant_id, id).
                $blueprint->unique(['tenant_id', 'id']);
            });

            self::applyTenantDefaultsAndRls($table);
            self::guardAcademicYearWritesIfApplicable($table);
            self::applyPendingTranslationChecks($table);
        } finally {
            self::clearCreationState($table);
        }

        // hasColumn, no hasTable: hasta 0.8.4 existe un `users` heredado del
        // starter kit de Laravel sin tenant_id ni UNIQUE (tenant_id, id) —
        // un FK compuesto contra esa tabla fallaría igual que contra una
        // tabla inexistente. hasColumn distingue ese `users` provisional
        // del `users` de tenant que crea 0.8.4. Cuando $table es el propio
        // `users`, la comprobación ya ve la columna (la acabamos de crear
        // arriba) y añade el FK autorreferenciado sin caso especial.
        if (Schema::connection('pgsql_owner')->hasColumn('users', 'tenant_id')) {
            self::addAuthorshipForeignKeys($table);
        }
    }

    /**
     * Tabla de tenant append-only (ADR-034 §3, §6): sin deleted_at (borrado
     * lógico en una tabla append-only no significa nada), sin
     * created_by/updated_by (lleva su propio actor_user_id, más rico), y
     * con REVOKE UPDATE, DELETE para que la inmutabilidad la garantice el
     * motor y no una convención de aplicación (misma lección que el bug 6
     * de 0.7 con failed_jobs: un REVOKE que no se escribe explícitamente no
     * existe).
     */
    public static function tenantTableAppendOnly(string $table, Closure $columns): void
    {
        self::assertSafeIdentifier($table);

        self::$tableInProgress = $table;

        try {
            Schema::connection('pgsql_owner')->create($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->id();
                $blueprint->foreignId('tenant_id');

                $columns($blueprint);

                $blueprint->unique(['tenant_id', 'id']);
            });

            self::applyTenantDefaultsAndRls($table);
            self::guardAcademicYearWritesIfApplicable($table);
            self::applyPendingTranslationChecks($table);
        } finally {
            self::clearCreationState($table);
        }

        // De los dos roles, no solo plataforma_app: plataforma_platform
        // tiene BYPASSRLS (ADR-033 §5) y es la conexión que más fácil
        // tocaría estas tablas por error en un futuro script de
        // mantenimiento entre tenants. "Inmutabilidad forzada en el motor"
        // no lo es si un camino de escritura se queda sin revocar
        // (hallazgo propio de revisión, ver `docs/historial/0.8-modelo-de-datos-nucleo.md`).
        DB::connection('pgsql_owner')->statement(
            "REVOKE UPDATE, DELETE ON {$table} FROM plataforma_app, plataforma_platform"
        );
    }

    /**
     * Columna + clave foránea compuesta (tenant_id, $column) REFERENCES
     * $referencedTable (tenant_id, id) + índice (tenant_id, $column) en una
     * sola llamada (ADR-033 §6, ADR-034 §4/§7). $column es NOT NULL siempre —
     * ADR-034 §4: "academic_year_id es NOT NULL o no existe la columna,
     * nunca nullable"; el mismo criterio aplica a cualquier referencia
     * obligatoria entre tablas de tenant. Una referencia opcional no usa
     * este helper: se declara a mano, como created_by/updated_by.
     *
     * Índice (issue #407, CA-ACAD-038): hasta 1.10 el docblock prometía un
     * índice que el código no creaba (PostgreSQL no indexa por sí solo el
     * lado hijo de una clave foránea). Desde 1.11 se crea
     * `(tenant_id, $column)`, con nombre explícito de a lo sumo 63 bytes
     * (ver `indexName()`): el nombre automático de Laravel
     * `{tabla}_{columnas}_index` se trunca en silencio como el de la FK.
     * **No es retroactivo**: las tablas ya migradas no lo ganan, igual que
     * `$constraintName` no cambió el DDL de las migraciones mezcladas (las
     * converge `2026_10_12_100000_add_tenant_foreign_key_indexes`, #412).
     *
     * `$withIndex = false` (#412): omite ese índice cuando la propia migración
     * declara a mano, **en la misma migración**, un índice **completo** (no
     * parcial) que empiece por `(tenant_id, $column)` y ya cubre la FK; si no,
     * el del ayudante sería redundante. Solo se usa en las cinco migraciones
     * antiguas que ya lo tenían a mano (excepción deliberada a «las migraciones
     * mezcladas no cambian»: no altera el esquema de ninguna base ya migrada,
     * no existe producción, y es la única forma de que una instalación nueva
     * converja con una migrada). Una migración nueva no lo necesita: no declara
     * ese índice a mano, y `TenantForeignKeyIndexesMigrationTest` y `AR-16`
     * vigilan que toda FK compuesta quede cubierta. Un índice parcial NO vale
     * para pedir la omisión.
     *
     * `$constraintName`: hallazgo de `db-reviewer` (REQ-AUTH-004, 1.4c,
     * punto de control 1). El nombre por defecto de Laravel para esta FK
     * compuesta (`{tabla}_tenant_id_{columna}_foreign`) supera los 63
     * bytes que PostgreSQL admite para un identificador en cuanto la
     * tabla o la columna son largas —`saml_identity_provider_settings` e
     * `identity_provider_certificates` son los primeros nombres del
     * esquema que lo hacen—, y el motor lo trunca en silencio, perdiendo
     * incluso el sufijo `_foreign`. Parámetro opcional y no retroactivo a
     * propósito: cambiar el nombre por defecto para toda llamada ya
     * existente alteraría el DDL que produce una instalación nueva de
     * migraciones ya mezcladas en `develop` (p. ej.
     * `identity_provider_secrets`, que **también** supera el límite —
     * hallazgo aparte, fuera del alcance de esta sesión, `CLAUDE.md §5`
     * severidad baja a reportar) sin que nadie lo pidiera para ese paso.
     * Cada llamada nueva que lo necesite pasa su propio nombre corto y
     * explícito.
     */
    public static function tenantForeignId(Blueprint $blueprint, string $column, string $referencedTable, ?string $constraintName = null, bool $withIndex = true): void
    {
        self::assertSafeIdentifier($referencedTable);

        $blueprint->foreignId($column);
        $blueprint->foreign(['tenant_id', $column], $constraintName)->references(['tenant_id', 'id'])->on($referencedTable);

        if ($withIndex) {
            $blueprint->index(['tenant_id', $column], self::indexName($blueprint->getTable(), $column));
        }
    }

    /**
     * Longitud máxima de un identificador de PostgreSQL, en bytes.
     */
    public const IDENTIFIER_MAX_BYTES = 63;

    /**
     * Nombre del índice `(tenant_id, $column)` de `tenantForeignId()`:
     * `{tabla}__{columna}_tenant_idx` (doble guion bajo entre tabla y columna: ningún
     * nombre de tabla ni de columna del proyecto lo contiene, así que dos pares
     * distintos no dan el mismo nombre, #415), y si supera los 63 bytes, el prefijo
     * recortado más un sufijo `_` + 8 hex de `md5` del nombre completo, para
     * que sea determinista, único y entre en el límite sin truncado
     * silencioso del motor. El sufijo `_tenant_idx` (y no el
     * `_tenant_id_{columna}_index` automático de Laravel) evita chocar con un
     * índice homónimo declarado a mano en una migración.
     */
    public static function indexName(string $table, string $column): string
    {
        return self::boundedIdentifier("{$table}__{$column}", '_tenant_idx');
    }

    /**
     * ADR-062 §5.1: añade `<campo>_translations jsonb NOT NULL` y registra su
     * `CHECK` para crearlo justo después de la tabla (`tenantTable()` y
     * `tenantTableAppendOnly()` lo aplican). Nadie escribe el `CHECK` a mano
     * (`AR-16`). Se llama dentro del cierre de columnas:
     *
     *     TenantMigration::tenantTable('subjects', function (Blueprint $t): void {
     *         TenantMigration::translatableColumn($t, 'name');   // name_translations
     *     });
     *
     * Para una tabla que ya existe (fase *expand* con filas), no sirve: la
     * columna `NOT NULL` sin valor por defecto fallaría; ese caso lo decide la
     * especificación del módulo que lo necesite.
     */
    public static function translatableColumn(Blueprint $blueprint, string $field): void
    {
        self::assertSafeIdentifier($field);

        // Sin un `tenantTable()`/`tenantTableAppendOnly()` en curso sobre esta
        // misma tabla nadie aplicaría el CHECK: se falla en voz alta, nunca se
        // omite en silencio (revisión de `db-reviewer`, `AR-16` lo vigila
        // además sobre el esquema).
        if (self::$tableInProgress === null || self::$tableInProgress !== $blueprint->getTable()) {
            throw new LogicException(
                "translatableColumn({$field}) solo puede llamarse dentro del cierre de columnas de "
                .'TenantMigration::tenantTable() o tenantTableAppendOnly() sobre la misma tabla: '
                .'fuera de ellos el CHECK de ADR-062 §5.1 no se crearía. Para una tabla existente, '
                .'añade la columna y llama a addTranslationsCheck().'
            );
        }

        $column = "{$field}_translations";

        $blueprint->jsonb($column);

        self::$pendingTranslationChecks[$blueprint->getTable()][] = $column;
    }

    /** @var array<string, list<string>> tabla => columnas `*_translations` pendientes de su CHECK */
    private static array $pendingTranslationChecks = [];

    /** Tabla que `tenantTable()`/`tenantTableAppendOnly()` está creando ahora mismo; `null` fuera de ellos. */
    private static ?string $tableInProgress = null;

    /**
     * Limpia el estado de creación pase lo que pase (también si el `create`
     * falla): un estado huérfano haría que el CHECK de una tabla se aplicara a
     * otra posterior del mismo nombre, o que `translatableColumn()` creyera
     * estar dentro de una creación que ya terminó.
     */
    private static function clearCreationState(string $table): void
    {
        unset(self::$pendingTranslationChecks[$table]);
        self::$tableInProgress = null;
    }

    /**
     * Nombre de la restricción `CHECK` de una columna traducible
     * (`{tabla}__{columna}_check`, mismo separador no ambiguo que `indexName()`,
     * acotado a 63 bytes).
     */
    public static function translationsCheckName(string $table, string $column): string
    {
        return self::boundedIdentifier("{$table}__{$column}", '_check');
    }

    /**
     * ADR-062 §5.1: el `CHECK` del motor de una columna traducible, en línea y
     * sin función SQL compartida. Sobre tabla nueva o vacía (el
     * `ADD CONSTRAINT` sin `NOT VALID` valida todas las filas bajo
     * `ACCESS EXCLUSIVE`; sobre una tabla con filas usa el patrón de
     * `migracion-segura`). Un valor que no es un objeto JSON falla con
     * `check_violation` (23514), no con un error de operador (`CASE`).
     *
     * **Lo que el motor NO garantiza**: que los valores sean cadenas no vacías
     * (`{"en": ""}` y `{"en": "  "}` lo acepta), ni que las claves sean
     * idiomas activos o incluyan el idioma por defecto del centro. Eso varía por
     * centro o es validación de negocio y lo hace `TranslationsValidator`
     * (`ADR-062 §5.1` punto 4 y `§5.2`, `INV-010`); los tests lo fijan.
     *
     * 1. objeto JSON; 2. no vacío; 3. claves dentro del vocabulario cerrado;
     * 4. ningún valor que no sea cadena.
     */
    public static function addTranslationsCheck(string $table, string $column): void
    {
        self::assertSafeIdentifier($table);
        self::assertSafeIdentifier($column);

        $keys = implode(',', array_map(static fn (string $l): string => "'{$l}'", Locales::VOCABULARY));
        $name = self::translationsCheckName($table, $column);

        DB::connection('pgsql_owner')->statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ("
            ."CASE WHEN jsonb_typeof({$column}) = 'object' THEN ("
            ."{$column} <> '{}'::jsonb "
            ."AND ({$column} - ARRAY[{$keys}]) = '{}'::jsonb "
            ."AND NOT jsonb_path_exists({$column}, 'strict \$.* ? (@.type() != \"string\")')"
            .') ELSE false END)'
        );
    }

    private static function applyPendingTranslationChecks(string $table): void
    {
        $columns = self::$pendingTranslationChecks[$table] ?? [];
        unset(self::$pendingTranslationChecks[$table]);

        foreach ($columns as $column) {
            self::addTranslationsCheck($table, $column);
        }
    }

    /**
     * `{base}{sufijo}` con `base` = `{tabla}__{columna}`: el doble guion bajo
     * hace el nombre no ambiguo (issue #415; antes `a_b` + `c_id` y `a` +
     * `b_c_id` daban el mismo). Si no cabe en 63 bytes, prefijo recortado +
     * `_` + 8 hex de `md5` del nombre completo (que incluye el separador).
     */
    private static function boundedIdentifier(string $base, string $suffix): string
    {
        $name = $base.$suffix;

        if (strlen($name) <= self::IDENTIFIER_MAX_BYTES) {
            return $name;
        }

        $hash = substr(md5($name), 0, 8);
        $room = self::IDENTIFIER_MAX_BYTES - strlen($suffix) - strlen($hash) - 1;

        return substr($base, 0, $room).'_'.$hash.$suffix;
    }

    /**
     * ADR-057 §5.3, RN-CURSO-23: engancha el disparador
     * `academic_year_write_guard` (`BEFORE INSERT OR UPDATE OR DELETE … FOR
     * EACH ROW`, función `app.assert_academic_year_writable()`) a una tabla
     * que YA existe y a la que se acaba de añadir la columna
     * `academic_year_id` (en una tabla nueva lo hacen solos `tenantTable()`/
     * `tenantTableAppendOnly()`). Ningún módulo escribe el `CREATE TRIGGER`
     * a mano. `AR-13` comprueba el resultado sobre el esquema real.
     */
    public static function guardAcademicYearWrites(string $table): void
    {
        self::assertSafeIdentifier($table);

        $owner = DB::connection('pgsql_owner');

        // El tope de bloqueo es `SET LOCAL` (set_config(..., true)): fuera de una
        // transacción valdría solo para la sentencia suelta y no protegería el
        // CREATE TRIGGER. Se exige transacción de forma explícita (B-2).
        if ($owner->transactionLevel() < 1) {
            throw new RuntimeException(self::guardRequiresTransactionMessage($table));
        }

        // Idempotencia explícita (#385): un segundo CREATE TRIGGER fallaría con
        // un error genérico de PostgreSQL ("trigger ... already exists"); aquí
        // se dice qué ocurre y qué hacer.
        $exists = $owner->selectOne(
            'SELECT 1 AS ok FROM pg_trigger t WHERE t.tgrelid = ?::regclass AND t.tgname = ? AND NOT t.tgisinternal',
            [$table, 'academic_year_write_guard'],
        );

        if ($exists !== null) {
            throw new RuntimeException(self::guardAlreadyExistsMessage($table));
        }

        // CREATE TRIGGER toma SHARE ROW EXCLUSIVE sobre la tabla: sin tope,
        // una transacción larga sobre ella bloquearía el despliegue
        // indefinidamente y, detrás, todas las escrituras. Tope breve y local
        // a la transacción de la migración (SET LOCAL), restaurado después
        // para no afectar al resto de la migración. Si el bloqueo no se
        // obtiene, PostgreSQL aborta la transacción (55P03) y el valor local
        // desaparece con ella.
        $previous = $owner->selectOne("SELECT current_setting('lock_timeout') AS v")->v;
        $owner->selectOne("SELECT set_config('lock_timeout', ?, true)", [self::GUARD_LOCK_TIMEOUT]);

        $owner->statement(
            "CREATE TRIGGER academic_year_write_guard BEFORE INSERT OR UPDATE OR DELETE ON {$table} "
            .'FOR EACH ROW EXECUTE FUNCTION app.assert_academic_year_writable()'
        );

        $owner->selectOne("SELECT set_config('lock_timeout', ?, true)", [$previous]);
    }

    /** Tope de espera de bloqueo para enganchar el disparador (#385). */
    public const GUARD_LOCK_TIMEOUT = '5s';

    public static function guardRequiresTransactionMessage(string $table): string
    {
        return "guardAcademicYearWrites({$table}) debe ejecutarse dentro de una transacción de pgsql_owner: "
            .'el tope de bloqueo (lock_timeout) es local a la transacción y sin ella no protege el CREATE TRIGGER. '
            .'Las migraciones de Laravel ya corren en transacción; no lo llames desde fuera de una.';
    }

    public static function guardAlreadyExistsMessage(string $table): string
    {
        return "La tabla {$table} ya tiene el disparador academic_year_write_guard: guardAcademicYearWrites() "
            .'no es reejecutable. Si la migración se está repitiendo tras un fallo parcial, comprueba el estado '
            .'real (\\d+ '.$table.') antes de reintentar; si la tabla se creó con tenantTable()/tenantTableAppendOnly() '
            .'y ya tenía la columna academic_year_id, el disparador viene puesto y esta llamada sobra.';
    }

    private static function guardAcademicYearWritesIfApplicable(string $table): void
    {
        if (Schema::connection('pgsql_owner')->hasColumn($table, 'academic_year_id')) {
            self::guardAcademicYearWrites($table);
        }
    }

    /**
     * Cierra la clave foránea compuesta de created_by/updated_by hacia
     * `users` sobre una tabla creada antes de que `users` existiera.
     * Solo la llama la migración de 0.8.4, para academic_years y people.
     */
    public static function addAuthorshipForeignKeys(string $table): void
    {
        self::assertSafeIdentifier($table);

        Schema::connection('pgsql_owner')->table($table, function (Blueprint $blueprint): void {
            $blueprint->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'id'])->on('users');
            $blueprint->foreign(['tenant_id', 'updated_by'])->references(['tenant_id', 'id'])->on('users');
        });
    }

    /**
     * Tabla de plataforma pura (ADR-033 §7): sin tenant_id, sin RLS.
     * `REVOKE ALL ON <tabla> FROM plataforma_app`, y sobre la secuencia de
     * su clave `bigserial` — el `REVOKE` de tabla no la cubre, y
     * `01-tenancy.sql.tpl` concede `USAGE, SELECT ON SEQUENCES` por
     * defecto (ADR-047 §4.4, §11 punto 1, datos.md §12.1). No añade
     * timestamps, borrado lógico ni autoría: a diferencia de las tablas
     * de tenant, las de plataforma no comparten una forma única (algunas
     * son append-only, algunas no llevan `deleted_at`, alguna no lleva
     * clave `bigserial` en absoluto) — cada migración declara las suyas.
     *
     * Recomendación de ADR-047 §4.5: centralizar aquí el `REVOKE` de
     * tabla y de secuencia en un solo sitio, para que "trece tablas
     * escribiendo a mano su juego de GRANT/REVOKE" no sea la forma en
     * que uno de los trece se olvida.
     */
    public static function platformTable(string $table, Closure $columns): void
    {
        self::assertSafeIdentifier($table);

        Schema::connection('pgsql_owner')->create($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->id();

            $columns($blueprint);
        });

        self::revokeSequenceAndTable($table);
    }

    /**
     * El `REVOKE` de tabla y de secuencia por separado, para las tablas de
     * plataforma que no encajan en `platformTable()` — porque ya existen
     * (`tenants`) o porque su forma la fija otra cosa (`admin_action_logs`
     * y `tenant_lifecycle_events`, con la política `tenant_visibility` de
     * `ADR-047 §4.3`, que necesitan su propio `GRANT SELECT` de columnas
     * enumeradas antes de que este `REVOKE ALL` cierre el resto).
     */
    public static function revokeSequenceAndTable(string $table): void
    {
        self::assertSafeIdentifier($table);

        $connection = DB::connection('pgsql_owner');

        $connection->statement("REVOKE ALL ON {$table} FROM plataforma_app");
        $connection->statement("REVOKE ALL ON SEQUENCE {$table}_id_seq FROM plataforma_app");
    }

    private static function applyTenantDefaultsAndRls(string $table): void
    {
        $connection = DB::connection('pgsql_owner');

        $connection->statement("ALTER TABLE {$table} ALTER COLUMN tenant_id SET DEFAULT app.current_tenant_id()");
        $connection->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        $connection->statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        $connection->statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$table}
                USING      (tenant_id = app.current_tenant_id())
                WITH CHECK (tenant_id = app.current_tenant_id())
            SQL);
    }

    private static function assertSafeIdentifier(string $name): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Nombre de tabla no válido para tenantTable(): {$name}");
        }
    }
}
